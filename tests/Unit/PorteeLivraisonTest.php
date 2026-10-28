<?php

namespace Tests\Unit;

use App\Models\City;
use App\Models\Restaurant;
use App\Models\Town;
use App\Services\PorteeLivraison;
use App\Settings\FoodSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La regle de distance, et elle seule.
 *
 * Gombe (-4.3045, 15.3075) et Limete (-4.3700, 15.3500) sont a environ 9 km.
 * Lubumbashi (-11.6600, 27.4800) est a plus de 1 500 km de Kinshasa.
 */
class PorteeLivraisonTest extends TestCase
{
    use RefreshDatabase;

    private const GOMBE = [-4.3045, 15.3075];

    private const LIMETE = [-4.3700, 15.3500];

    private const LUBUMBASHI = [-11.6600, 27.4800];

    /** Casablanca : plus de 5 000 km de Kinshasa. */
    private const MAROC = [33.5731, -7.5898];

    protected function setUp(): void
    {
        parent::setUp();

        FoodSettings::fake([
            'max_delivery_radius_km' => 30.0,
            'out_of_radius_message' => ':restaurant est à :distance km. Rayon : :rayon km.',
            'use_client_position' => false,
            'city_first' => false,
            'stock_actif' => true,
            'montant_minimum_commande' => 0.0,
        ], loadMissingValues: false);
    }

    private function portee(): PorteeLivraison
    {
        return app(PorteeLivraison::class);
    }

    /**
     * Efface les reglages ET l'instance truquee du conteneur.
     *
     * Supprimer les lignes ne suffit pas : `FoodSettings::fake()` a deja
     * enregistre un objet charge dans le conteneur, et il continuerait de
     * repondre. C'est l'etat reel d'un serveur dont les migrations n'ont pas
     * tourne qu'on veut reproduire.
     */
    private function sansAucunReglage(): void
    {
        DB::table('settings')->where('group', 'food')->delete();
        app()->forgetInstance(FoodSettings::class);
    }

    public function test_un_restaurant_dans_le_rayon_est_autorise(): void
    {
        $restaurant = Restaurant::factory()->located(...self::GOMBE)->create();

        $portee = $this->portee()->pour($restaurant, null, ...self::LIMETE);

        $this->assertTrue($portee->autorise);
        $this->assertNull($portee->message);
        $this->assertLessThan(30.0, $portee->distance_km);
    }

    public function test_un_restaurant_hors_rayon_est_refuse_avec_la_distance_dans_le_message(): void
    {
        $restaurant = Restaurant::factory()
            ->located(...self::LUBUMBASHI)
            ->create(['name' => 'Al-Dar']);

        $portee = $this->portee()->pour($restaurant, null, ...self::GOMBE);

        $this->assertFalse($portee->autorise);
        $this->assertGreaterThan(1000.0, $portee->distance_km);
        $this->assertStringContainsString('Al-Dar', $portee->message);
        $this->assertStringContainsString((string) $portee->distance_km, $portee->message);
        $this->assertStringContainsString('30', $portee->message);
    }

    /** Review Focus 1 : la borne est inclusive. */
    public function test_une_distance_egale_au_rayon_est_autorisee(): void
    {
        // Rayon 0 et distance 0 : c'est la comparaison qu'on teste, pas
        // Haversine. Chercher deux points a exactement 30,000 km serait
        // fragile pour la meme assertion.
        $city = City::factory()->create([
            'title' => 'Test', 'is_active' => true, 'max_delivery_radius_km' => 0.0,
        ]);
        $town = Town::factory()->create([
            'city_id' => $city->id, 'latitude' => 1.0, 'longitude' => 1.0,
        ]);
        $restaurant = Restaurant::factory()->create([
            'town_id' => $town->id, 'location' => null,
        ]);

        $portee = $this->portee()->pour($restaurant, $town, 1.0, 1.0);

        $this->assertSame(0.0, $portee->distance_km);
        $this->assertSame(0.0, $portee->rayon_km);
        $this->assertTrue($portee->autorise);
    }

    /** Review Focus 2 : un GPS qui n'a pas fixe renvoie le point zero. */
    public function test_une_position_de_livraison_a_zero_zero_est_ignoree(): void
    {
        $restaurant = Restaurant::factory()->located(...self::GOMBE)->create();

        $portee = $this->portee()->pour($restaurant, null, 0.0, 0.0);

        $this->assertTrue($portee->autorise);
        $this->assertNull($portee->distance_km);
    }

    /**
     * Un restaurant pose sur (0, 0) retombe sur sa commune.
     *
     * Le formulaire d'administration expose deux champs numeriques : les
     * laisser a zero est la faute de saisie la plus probable. Prise au mot,
     * elle placerait le restaurant a 5 300 km de tous ses clients — il
     * disparaitrait de la recherche et refuserait toutes ses commandes, sans
     * qu'aucun message ne le signale a l'administrateur.
     */
    public function test_un_restaurant_pose_sur_le_point_zero_retombe_sur_sa_commune(): void
    {
        $town = Town::factory()->create([
            'latitude' => self::GOMBE[0], 'longitude' => self::GOMBE[1],
        ]);
        $restaurant = Restaurant::factory()
            ->located(0.0, 0.0)
            ->create(['town_id' => $town->id]);

        $portee = $this->portee()->pour($restaurant, null, ...self::LIMETE);

        $this->assertTrue($portee->autorise);
        $this->assertLessThan(30.0, $portee->distance_km);
    }

    /** Une commune dont le centroide est reste a zero ne refuse rien. */
    public function test_une_commune_dont_le_centroide_vaut_zero_est_ignoree(): void
    {
        $town = Town::factory()->create(['latitude' => 0.0, 'longitude' => 0.0]);
        $restaurant = Restaurant::factory()->create([
            'town_id' => $town->id, 'location' => null,
        ]);

        $portee = $this->portee()->pour($restaurant, null, ...self::GOMBE);

        $this->assertTrue($portee->autorise);
        $this->assertNull($portee->distance_km);
    }

    /**
     * La position envoyee par le client ne peut pas contredire sa commune.
     *
     * Sinon la regle se contourne avec deux nombres dans le corps de la
     * requete : declarer une livraison a Lubumbashi, envoyer les coordonnees
     * de la Gombe, et commander dans un restaurant de la Gombe. La commande
     * serait enregistree sur Lubumbashi, avec les frais de Lubumbashi.
     *
     * C'est le motif qui a deja produit une faille sur ce depot — « le prix
     * vient du client ». Le centroide de la commune, lui, n'est pas
     * falsifiable : il sert d'arbitre.
     */
    public function test_une_position_incoherente_avec_la_commune_declaree_est_ecartee(): void
    {
        $restaurant = Restaurant::factory()->located(...self::GOMBE)->create();

        $lubumbashi = Town::factory()->create([
            'latitude' => self::LUBUMBASHI[0], 'longitude' => self::LUBUMBASHI[1],
        ]);

        // Le client declare livrer a Lubumbashi mais annonce etre a la Gombe,
        // a zero metre du restaurant.
        $portee = $this->portee()->pour($restaurant, $lubumbashi, ...self::GOMBE);

        $this->assertFalse($portee->autorise);
    }

    /** Une position coherente avec sa commune reste prioritaire. */
    public function test_une_position_coherente_prime_toujours_sur_le_centroide(): void
    {
        $restaurant = Restaurant::factory()->located(...self::GOMBE)->create();

        $gombe = Town::factory()->create([
            'latitude' => self::GOMBE[0], 'longitude' => self::GOMBE[1],
        ]);

        // Limete est a 9 km de la Gombe : plausible dans la meme agglomeration.
        $portee = $this->portee()->pour($restaurant, $gombe, ...self::LIMETE);

        $this->assertTrue($portee->autorise);
        $this->assertGreaterThan(5.0, $portee->distance_km);
    }

    public function test_sans_location_on_retombe_sur_le_centroide_de_la_commune(): void
    {
        $town = Town::factory()->create([
            'latitude' => self::LUBUMBASHI[0], 'longitude' => self::LUBUMBASHI[1],
        ]);
        $restaurant = Restaurant::factory()->create([
            'town_id' => $town->id, 'location' => null,
        ]);

        $portee = $this->portee()->pour($restaurant, null, ...self::GOMBE);

        $this->assertFalse($portee->autorise);
        $this->assertGreaterThan(1000.0, $portee->distance_km);
    }

    public function test_la_position_de_livraison_retombe_sur_le_centroide_de_sa_commune(): void
    {
        $restaurant = Restaurant::factory()->located(...self::GOMBE)->create();
        $livraison = Town::factory()->create([
            'latitude' => self::LUBUMBASHI[0], 'longitude' => self::LUBUMBASHI[1],
        ]);

        $portee = $this->portee()->pour($restaurant, $livraison, null, null);

        $this->assertFalse($portee->autorise);
    }

    /** Review Focus 5 : aucune donnee des deux cotes, aucune exception. */
    public function test_un_restaurant_sans_commune_ni_position_est_autorise(): void
    {
        $restaurant = Restaurant::factory()->create([
            'town_id' => null, 'location' => null,
        ]);

        $portee = $this->portee()->pour($restaurant, null, ...self::GOMBE);

        $this->assertTrue($portee->autorise);
        $this->assertNull($portee->distance_km);
        $this->assertNull($portee->message);
    }

    public function test_le_rayon_de_la_ville_du_restaurant_prime_sur_le_global(): void
    {
        $city = City::factory()->create([
            'title' => 'Lubumbashi', 'is_active' => true, 'max_delivery_radius_km' => 5.0,
        ]);
        $town = Town::factory()->create(['city_id' => $city->id]);
        $restaurant = Restaurant::factory()
            ->located(...self::GOMBE)
            ->create(['town_id' => $town->id]);

        $this->assertSame(5.0, $this->portee()->rayonPour($restaurant->fresh()));

        // 9 km entre Gombe et Limete, rayon 5 : refuse.
        $portee = $this->portee()->pour($restaurant->fresh(), null, ...self::LIMETE);
        $this->assertFalse($portee->autorise);
    }

    /**
     * Les reglages absents ne cassent pas le catalogue.
     *
     * `settings` peut etre vide : migrations pas encore jouees apres un
     * deploiement — c'est deja arrive en production (T76, 500 avec 23
     * migrations en attente). La portee est alors inconnue, et une portee
     * inconnue n'interdit rien. Le contraire ferait tomber la page la plus
     * consultee de l'application pour un reglage manquant.
     */
    public function test_des_reglages_absents_n_empechent_pas_de_commander(): void
    {
        $this->sansAucunReglage();

        $restaurant = Restaurant::factory()->located(...self::LUBUMBASHI)->create();

        $portee = $this->portee()->pour($restaurant, null, ...self::GOMBE);

        $this->assertTrue($portee->autorise);
        $this->assertNull($portee->rayon_km);
        $this->assertNull($portee->message);
    }

    /** Une ville qui a son rayon n'a pas besoin du reglage global. */
    public function test_le_rayon_d_une_ville_s_applique_meme_sans_reglage_global(): void
    {
        $this->sansAucunReglage();

        $city = City::factory()->create([
            'title' => 'Kinshasa', 'is_active' => true, 'max_delivery_radius_km' => 5.0,
        ]);
        $town = Town::factory()->create(['city_id' => $city->id]);
        $restaurant = Restaurant::factory()
            ->located(...self::GOMBE)
            ->create(['town_id' => $town->id]);

        $portee = $this->portee()->pour($restaurant->fresh(), null, ...self::LIMETE);

        $this->assertFalse($portee->autorise);
    }

    // --- La position physique du client -------------------------------------

    /** Allume l'option sans toucher aux autres reglages. */
    private function avecPositionClient(bool $actif): void
    {
        FoodSettings::fake([
            'max_delivery_radius_km' => 30.0,
            'out_of_radius_message' => ':restaurant est à :distance km. Rayon : :rayon km.',
            'use_client_position' => $actif,
            'city_first' => false,
            'stock_actif' => true,
            'montant_minimum_commande' => 0.0,
        ], loadMissingValues: false);
    }

    /**
     * Option eteinte : la position du client n'existe pas pour la regle.
     *
     * C'est le comportement d'aujourd'hui, et le defaut. Commander pour sa
     * famille a Kinshasa depuis le Maroc doit rester possible.
     */
    public function test_option_eteinte_la_position_du_client_est_ignoree(): void
    {
        $this->avecPositionClient(false);

        $restaurant = Restaurant::factory()->located(...self::GOMBE)->create();

        // Le client est au Maroc, la livraison se fait a Limete.
        $portee = $this->portee()->pour(
            $restaurant, null, self::LIMETE[0], self::LIMETE[1], self::MAROC,
        );

        $this->assertTrue($portee->autorise);
    }

    /**
     * Option allumee, client trop loin : refuse, meme si la livraison est
     * a cote du restaurant.
     *
     * « Je suis au Maroc, je dois voir que les restaurants proches. »
     */
    public function test_option_allumee_un_client_trop_loin_est_refuse(): void
    {
        $this->avecPositionClient(true);

        $restaurant = Restaurant::factory()
            ->located(...self::GOMBE)
            ->create(['name' => 'Al-Dar']);

        $portee = $this->portee()->pour(
            $restaurant, null, self::LIMETE[0], self::LIMETE[1], self::MAROC,
        );

        $this->assertFalse($portee->autorise);
        $this->assertStringContainsString('Al-Dar', $portee->message);
    }

    /** Option allumee, client et livraison a portee : autorise. */
    public function test_option_allumee_un_client_proche_passe(): void
    {
        $this->avecPositionClient(true);

        $restaurant = Restaurant::factory()->located(...self::GOMBE)->create();

        $portee = $this->portee()->pour(
            $restaurant, null, self::LIMETE[0], self::LIMETE[1], self::LIMETE,
        );

        $this->assertTrue($portee->autorise);
    }

    /**
     * Option allumee, client devant le restaurant mais livraison lointaine :
     * refuse.
     *
     * Sans ce cas, allumer l'option ouvrirait un contournement de la regle
     * d'adresse : il suffirait d'etre sur place pour faire livrer n'importe ou.
     */
    public function test_option_allumee_une_livraison_lointaine_reste_refusee(): void
    {
        $this->avecPositionClient(true);

        $restaurant = Restaurant::factory()->located(...self::GOMBE)->create();

        $lubumbashi = Town::factory()->create([
            'latitude' => self::LUBUMBASHI[0], 'longitude' => self::LUBUMBASHI[1],
        ]);

        $portee = $this->portee()->pour(
            $restaurant, $lubumbashi, null, null, self::GOMBE,
        );

        $this->assertFalse($portee->autorise);
    }

    /**
     * Option allumee mais aucune position : on retombe sur l'adresse seule.
     *
     * Un client qui refuse la permission de localisation ne doit pas se
     * retrouver devant un catalogue vide. C'est la doctrine de cette classe
     * pour toute donnee manquante, et elle vaut ici aussi.
     */
    public function test_option_allumee_sans_position_rien_n_est_refuse_de_ce_fait(): void
    {
        $this->avecPositionClient(true);

        $restaurant = Restaurant::factory()->located(...self::GOMBE)->create();

        $portee = $this->portee()->pour(
            $restaurant, null, self::LIMETE[0], self::LIMETE[1], null,
        );

        $this->assertTrue($portee->autorise);
    }

    /** Le point zero vaut aussi pour la position du client. */
    public function test_une_position_client_a_zero_zero_est_ignoree(): void
    {
        $this->avecPositionClient(true);

        $restaurant = Restaurant::factory()->located(...self::GOMBE)->create();

        $portee = $this->portee()->pour(
            $restaurant, null, self::LIMETE[0], self::LIMETE[1], [0.0, 0.0],
        );

        $this->assertTrue($portee->autorise);
    }

    // --- La ville prime sur la distance --------------------------------------

    /** Allume « la ville prime » sans toucher aux autres reglages. */
    private function avecVillePrioritaire(bool $actif): void
    {
        FoodSettings::fake([
            'max_delivery_radius_km' => 30.0,
            'out_of_radius_message' => ':restaurant est à :distance km. Rayon : :rayon km.',
            'use_client_position' => false,
            'city_first' => $actif,
            'stock_actif' => true,
            'montant_minimum_commande' => 0.0,
        ], loadMissingValues: false);
    }

    /**
     * Deux adresses d'une meme ville se livrent, meme loin.
     *
     * Kinshasa fait plus de quarante kilometres de large : un rayon de trente
     * y refusait des livraisons parfaitement normales.
     */
    public function test_la_ville_prime_une_longue_distance_dans_la_meme_ville_passe(): void
    {
        $this->avecVillePrioritaire(true);

        $kinshasa = City::factory()->create(['title' => 'Kinshasa', 'is_active' => true]);

        $gombe = Town::factory()->create([
            'city_id' => $kinshasa->id,
            'latitude' => self::GOMBE[0], 'longitude' => self::GOMBE[1],
        ]);
        $maluku = Town::factory()->create([
            'city_id' => $kinshasa->id,
            // Maluku, extremite est de Kinshasa : bien au-dela de 30 km.
            'latitude' => -4.0700, 'longitude' => 15.5400,
        ]);

        $restaurant = Restaurant::factory()->create([
            'town_id' => $gombe->id, 'location' => null,
        ]);

        $portee = $this->portee()->pour($restaurant->fresh(), $maluku);

        $this->assertTrue($portee->autorise);
    }

    /** Eteinte, la meme situation est refusee : c'est le comportement actuel. */
    public function test_sans_l_option_la_longue_distance_reste_refusee(): void
    {
        $this->avecVillePrioritaire(false);

        $kinshasa = City::factory()->create(['title' => 'Kinshasa', 'is_active' => true]);

        $gombe = Town::factory()->create([
            'city_id' => $kinshasa->id,
            'latitude' => self::GOMBE[0], 'longitude' => self::GOMBE[1],
        ]);
        $maluku = Town::factory()->create([
            'city_id' => $kinshasa->id,
            'latitude' => -4.0700, 'longitude' => 15.5400,
        ]);

        $restaurant = Restaurant::factory()->create([
            'town_id' => $gombe->id, 'location' => null,
        ]);

        $this->assertFalse($this->portee()->pour($restaurant->fresh(), $maluku)->autorise);
    }

    /**
     * Villes differentes : le rayon reprend la main.
     *
     * Kinshasa et Brazzaville se font face, a une dizaine de kilometres. Deux
     * villes distinctes, mais une livraison plausible — c'est le rayon qui
     * doit trancher, pas le nom de la ville.
     */
    public function test_villes_differentes_le_rayon_reprend_la_main(): void
    {
        $this->avecVillePrioritaire(true);

        $kinshasa = City::factory()->create(['title' => 'Kinshasa', 'is_active' => true]);
        $brazza = City::factory()->create(['title' => 'Brazzaville', 'is_active' => true]);

        $gombe = Town::factory()->create([
            'city_id' => $kinshasa->id,
            'latitude' => self::GOMBE[0], 'longitude' => self::GOMBE[1],
        ]);
        $centre = Town::factory()->create([
            'city_id' => $brazza->id,
            'latitude' => -4.2634, 'longitude' => 15.2429,
        ]);

        $restaurant = Restaurant::factory()->create([
            'town_id' => $gombe->id, 'location' => null,
        ]);

        // Une dizaine de kilometres : dans le rayon, donc autorise.
        $this->assertTrue($this->portee()->pour($restaurant->fresh(), $centre)->autorise);
    }

    /** Villes differentes et loin : refuse, comme avant. */
    public function test_villes_differentes_et_eloignees_restent_refusees(): void
    {
        $this->avecVillePrioritaire(true);

        $kinshasa = City::factory()->create(['title' => 'Kinshasa', 'is_active' => true]);
        $lubumbashi = City::factory()->create(['title' => 'Lubumbashi', 'is_active' => true]);

        $gombe = Town::factory()->create([
            'city_id' => $kinshasa->id,
            'latitude' => self::GOMBE[0], 'longitude' => self::GOMBE[1],
        ]);
        $centre = Town::factory()->create([
            'city_id' => $lubumbashi->id,
            'latitude' => self::LUBUMBASHI[0], 'longitude' => self::LUBUMBASHI[1],
        ]);

        $restaurant = Restaurant::factory()->create([
            'town_id' => $gombe->id, 'location' => null, 'name' => 'Al-Dar',
        ]);

        $portee = $this->portee()->pour($restaurant->fresh(), $centre);

        $this->assertFalse($portee->autorise);
        $this->assertStringContainsString('Al-Dar', $portee->message);
    }

    /** Ville inconnue d'un cote : le rayon tranche, comme avant. */
    public function test_ville_inconnue_le_rayon_tranche(): void
    {
        $this->avecVillePrioritaire(true);

        // Commune sans ville rattachee, des deux cotes.
        $depart = Town::factory()->create([
            'city_id' => null,
            'latitude' => self::GOMBE[0], 'longitude' => self::GOMBE[1],
        ]);
        $arrivee = Town::factory()->create([
            'city_id' => null,
            'latitude' => self::LUBUMBASHI[0], 'longitude' => self::LUBUMBASHI[1],
        ]);

        $restaurant = Restaurant::factory()->create([
            'town_id' => $depart->id, 'location' => null,
        ]);

        $this->assertFalse($this->portee()->pour($restaurant->fresh(), $arrivee)->autorise);
    }

    /**
     * La position l'emporte sur la ville.
     *
     * « Je suis au Maroc mais je vois MOOD qui est a Gombe ? c'est pas
     * normal. » Quand l'option de position est allumee, etre loin suffit a
     * refuser, meme si la livraison se fait dans la ville du restaurant.
     *
     * C'est la contrepartie assumee : tant que l'option est allumee, on ne
     * commande plus pour sa famille depuis l'etranger. L'option est eteinte
     * par defaut, et c'est precisement ce qu'elle sert a decider.
     */
    public function test_la_position_l_emporte_sur_la_ville(): void
    {
        FoodSettings::fake([
            'max_delivery_radius_km' => 30.0,
            'out_of_radius_message' => ':restaurant est à :distance km. Rayon : :rayon km.',
            'use_client_position' => true,
            'city_first' => true,
            'stock_actif' => true,
            'montant_minimum_commande' => 0.0,
        ], loadMissingValues: false);

        $kinshasa = City::factory()->create(['title' => 'Kinshasa', 'is_active' => true]);

        $gombe = Town::factory()->create([
            'city_id' => $kinshasa->id,
            'latitude' => self::GOMBE[0], 'longitude' => self::GOMBE[1],
        ]);

        $restaurant = Restaurant::factory()->create([
            'town_id' => $gombe->id, 'location' => null,
        ]);

        // Le client est au Maroc, et il fait livrer a Gombe, ou se trouve le
        // restaurant. La ville concorde, mais lui est a 5 000 km.
        $portee = $this->portee()->pour($restaurant->fresh(), $gombe, null, null, self::MAROC);

        $this->assertFalse($portee->autorise);
    }

    /**
     * La position garde son role quand la ville ne tranche pas.
     *
     * Villes differentes : c'est la position qui doit refuser, sinon allumer
     * l'option « ville » desactiverait l'autre en silence.
     */
    public function test_la_position_refuse_encore_quand_les_villes_different(): void
    {
        FoodSettings::fake([
            'max_delivery_radius_km' => 30.0,
            'out_of_radius_message' => ':restaurant est à :distance km. Rayon : :rayon km.',
            'use_client_position' => true,
            'city_first' => true,
            'stock_actif' => true,
            'montant_minimum_commande' => 0.0,
        ], loadMissingValues: false);

        $kinshasa = City::factory()->create(['title' => 'Kinshasa', 'is_active' => true]);
        $lubumbashi = City::factory()->create(['title' => 'Lubumbashi', 'is_active' => true]);

        $gombe = Town::factory()->create([
            'city_id' => $kinshasa->id,
            'latitude' => self::GOMBE[0], 'longitude' => self::GOMBE[1],
        ]);
        $ailleurs = Town::factory()->create([
            'city_id' => $lubumbashi->id,
            'latitude' => self::LUBUMBASHI[0], 'longitude' => self::LUBUMBASHI[1],
        ]);

        $restaurant = Restaurant::factory()->create([
            'town_id' => $gombe->id, 'location' => null,
        ]);

        $portee = $this->portee()->pour($restaurant->fresh(), $ailleurs, null, null, self::MAROC);

        $this->assertFalse($portee->autorise);
    }

    public function test_une_ville_sans_surcharge_applique_le_rayon_global(): void
    {
        $city = City::factory()->create(['title' => 'Kinshasa', 'is_active' => true]);
        $town = Town::factory()->create(['city_id' => $city->id]);
        $restaurant = Restaurant::factory()
            ->located(...self::GOMBE)
            ->create(['town_id' => $town->id]);

        $this->assertSame(30.0, $this->portee()->rayonPour($restaurant->fresh()));
    }
}
