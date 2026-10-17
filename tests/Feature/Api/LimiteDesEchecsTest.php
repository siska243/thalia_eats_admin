<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Settings\RentalSettings;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Le filet general sur les actions qui ECHOUENT.
 *
 * Le proprietaire, le 17 octobre : « n'oublie pas de mettre le rate limite
 * pour toutes les actions qui echouent, c'est important cote securite ».
 *
 * Un attaquant qui cherche une faille enchaine les refus : identifiants
 * chiffres devines, parametres malformes, routes tatonnees. Hors des deux ou
 * trois chemins deja gardes (codes de livraison, authentification), personne
 * ne comptait ces refus.
 */
class LimiteDesEchecsTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * `DatabaseTruncation` vide la table des reglages : sans ce faux,
         * toute route qui lit RentalSettings repond 500, et le test
         * mesurerait une panne au lieu d'un refus.
         */
        RentalSettings::fake([
            'deposit_percentage' => 10.0,
            'refund_percentage' => 100.0,
            'minimum_hours' => 1.0,
            'pickup_code_max_attempts' => 3,
            'hours_min' => 1,
            'hours_max' => 23,
            'days_min' => 1,
            'days_max' => 30,
            'lead_time_minutes' => 60,
            'whatsapp_number' => '',
            'brand_food' => 'Thalia Eats',
            'brand_rental' => 'Thalia Trans',
            'out_of_zone_message' => 'Hors zone.',
        ], loadMissingValues: false);

        /*
         * Le filet est eteint dans `phpunit.xml`, pour que les dizaines de
         * refus attendus des autres suites ne s'additionnent pas. Ici on le
         * rallume : c'est le seul endroit qui le mesure.
         */
        config(['securite.limite_echecs.actif' => true]);

        RateLimiter::clear('echecs:ip127.0.0.1:minute');
        RateLimiter::clear('echecs:ip127.0.0.1:heure');
    }

    public function test_une_rafale_de_refus_finit_bloquee(): void
    {
        $derniere = null;

        /*
         * La borne par adresse est a trente, et le limiteur de debit general
         * a soixante : on en tente trente-cinq, assez pour franchir la
         * premiere sans atteindre la seconde — sinon on mesurerait le
         * mauvais garde.
         */
        for ($i = 0; $i < 35; $i++) {
            $derniere = $this->getJson('/api/rental/vehicles/inexistant-'.$i);
        }

        $derniere->assertStatus(429);
        $derniere->assertJsonPath('title', 'Trop de tentatives');
    }

    /**
     * LE POINT QUI COMPTE : un usage normal ne croise jamais cette borne.
     *
     * Un limiteur sur TOUTES les requetes punirait le client qui consulte
     * beaucoup. Celui-ci ne voit que celui qui se trompe beaucoup.
     */
    public function test_les_requetes_qui_reussissent_ne_comptent_pas(): void
    {
        for ($i = 0; $i < 40; $i++) {
            $this->getJson('/api/rental/config')->assertSuccessful();
        }

        // Quarante succes plus tard, le compteur est toujours a zero : un
        // refus passe encore.
        $this->getJson('/api/rental/vehicles/inexistant')->assertStatus(404);
    }

    /**
     * Le compteur suit le COMPTE quand on sait qui parle.
     *
     * Derriere un operateur mobile, des milliers de clients partagent une
     * poignee d'adresses : compter par IP seule les bloquerait les uns les
     * autres.
     */
    public function test_le_compteur_suit_le_compte_et_non_l_adresse(): void
    {
        $premier = User::factory()->create();
        $second = User::factory()->create();

        Sanctum::actingAs($premier, ['*']);

        // Vingt-cinq refus : au-dela de la borne du COMPTE (20), en deca de
        // celle de l'adresse (60).
        for ($i = 0; $i < 25; $i++) {
            $this->getJson('/api/rental/vehicles/inexistant-'.$i);
        }

        $this->getJson('/api/rental/vehicles/encore')->assertStatus(429);

        // Le second compte, sur la meme adresse, n'est pas puni pour l'autre.
        Sanctum::actingAs($second, ['*']);

        $this->getJson('/api/rental/vehicles/inexistant')->assertStatus(404);
    }

    /** Une panne de notre cote n'enferme pas l'appelant dehors. */
    public function test_le_429_ne_se_compte_pas_lui_meme(): void
    {
        for ($i = 0; $i < 35; $i++) {
            $this->getJson('/api/rental/vehicles/inexistant-'.$i);
        }

        $bloque = $this->getJson('/api/rental/vehicles/encore');
        $bloque->assertStatus(429);

        $premierDelai = (int) preg_replace('/\D/', '', $bloque->json('message'));

        // Marteler la porte ne rallonge pas la peine.
        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/api/rental/vehicles/encore');
        }

        $apres = $this->getJson('/api/rental/vehicles/encore');
        $secondDelai = (int) preg_replace('/\D/', '', $apres->json('message'));

        $this->assertLessThanOrEqual($premierDelai, $secondDelai);
    }
}
