<?php

namespace Tests\Feature\Services;

use App\Services\Geocodage\GeocodageIndisponible;
use App\Services\Geocodage\Nominatim;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Retrouver une position a partir d'une adresse, par OpenStreetMap.
 *
 * « On peut utiliser OSM pour recuperer la position a partir de
 * l'adresse. » OpenStreetMap et non Google : aucune cle a commiter, comme
 * pour la carte de suivi.
 *
 * AUCUNE REQUETE REELLE ICI : `Http::fake()` intercepte tout. Nominatim est
 * tenu par des benevoles et sa politique d'usage interdit le martelage —
 * une suite de tests qui l'interroge vraiment ferait bannir l'adresse du
 * serveur, et l'administration entiere perdrait la fonction.
 */
class GeocodageNominatimTest extends TestCase
{
    private function reponse(array $corps): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response($corps)]);
    }

    public function test_une_adresse_connue_rend_sa_position(): void
    {
        $this->reponse([[
            'lat' => '-4.3045',
            'lon' => '15.3075',
            'display_name' => 'Avenue du Commerce, Gombe, Kinshasa',
        ]]);

        $position = app(Nominatim::class)->chercher('Avenue du Commerce', 'Gombe Kinshasa');

        $this->assertEqualsWithDelta(-4.3045, $position['lat'], 0.0001);
        $this->assertEqualsWithDelta(15.3075, $position['lng'], 0.0001);
        $this->assertSame('Avenue du Commerce, Gombe, Kinshasa', $position['libelle']);
    }

    /** Une adresse inconnue rend null, et non une position inventee. */
    public function test_une_adresse_inconnue_ne_rend_rien(): void
    {
        $this->reponse([]);

        $this->assertNull(app(Nominatim::class)->chercher('Rue qui n’existe pas'));
    }

    /**
     * Le service est identifie, comme sa politique l'exige.
     *
     * Sans `User-Agent`, Nominatim refuse la requete. Ce test fige
     * l'en-tete : le retirer par megarde casserait la fonction en silence,
     * et seul un administrateur s'en apercevrait, un jour, en cliquant.
     */
    public function test_la_requete_s_identifie_et_se_limite_a_la_rdc(): void
    {
        $this->reponse([['lat' => '-4.3', 'lon' => '15.3']]);

        app(Nominatim::class)->chercher('Gombe');

        Http::assertSent(function ($requete): bool {
            /*
             * Le nom et le contact viennent de la configuration : le test
             * verifie que l'en-tete est RENSEIGNE, pas qu'il contient une
             * chaine ecrite en dur — ce serait figer ce qu'on vient
             * justement de rendre reglable.
             */
            $agent = $requete->header('User-Agent')[0] ?? '';

            return str_contains($agent, (string) config('app.name'))
                && str_contains($agent, (string) config('geocodage.contact'))
                && str_contains($requete->url(), 'countrycodes='.config('geocodage.pays'));
        });
    }

    /**
     * UN REFUS N'EST PAS UNE ADRESSE INTROUVABLE, et c'est arrive en vrai.
     *
     * Le 17 octobre, Nominatim rendait 403 parce que le contact declare
     * valait `hello@example.com` — la valeur par defaut de
     * `MAIL_FROM_ADDRESS`. L'administration affichait « Adresse
     * introuvable », ce qui envoyait reecrire une adresse parfaitement
     * juste.
     *
     * Les deux cas sont desormais distincts : `null` quand la carte ne
     * connait pas l'adresse, une exception quand le service refuse.
     */
    public function test_un_refus_du_service_se_distingue_d_une_adresse_inconnue(): void
    {
        Http::fake(['*' => Http::response('Access denied', 403)]);

        $this->expectException(GeocodageIndisponible::class);

        app(Nominatim::class)->chercher('Gombe');
    }

    /** Le contact declare doit etre une vraie adresse, pas un exemple. */
    public function test_le_contact_par_defaut_n_est_pas_une_adresse_factice(): void
    {
        $contact = (string) config('geocodage.contact');

        $this->assertNotEmpty($contact);
        $this->assertStringNotContainsString('example.com', $contact);
    }

    /**
     * Une panne reseau, elle, ne leve rien : l'administrateur saisit les
     * coordonnees a la main, comme avant.
     */
    public function test_une_panne_reseau_ne_fait_pas_tomber_le_formulaire(): void
    {
        Http::fake(fn () => throw new ConnectionException('injoignable'));

        $this->assertNull(app(Nominatim::class)->chercher('Gombe'));
    }

    /** Une adresse vide ne part meme pas : on n'interroge pas pour rien. */
    public function test_une_adresse_vide_n_appelle_personne(): void
    {
        Http::fake();

        $this->assertNull(app(Nominatim::class)->chercher('   '));

        Http::assertNothingSent();
    }

    /**
     * LE CAS QUI A ECHOUE EN VRAI, releve le 17 octobre.
     *
     * « 9257 Av. Batetela ( galerie la fontaine ) » ne rend RIEN sur
     * Nominatim : le numero de rue et la precision entre parentheses sont du
     * detail que la carte ne porte pas. « Avenue Batetela Gombe Kinshasa »,
     * lui, rend la bonne rue.
     *
     * Le service essaie donc l'adresse telle quelle, puis nettoyee. Ce test
     * fige les deux appels et leur ordre : sans le second palier, le bouton
     * ne remonterait toujours rien.
     */
    public function test_une_adresse_trop_detaillee_est_reessayee_nettoyee(): void
    {
        $appels = [];

        Http::fake(function ($requete) use (&$appels) {
            $appels[] = urldecode($requete->url());

            // Le premier palier, trop detaille, ne rend rien.
            if (str_contains(urldecode($requete->url()), '9257')) {
                return Http::response([]);
            }

            return Http::response([[
                'lat' => '-4.3163',
                'lon' => '15.2880',
                'display_name' => 'Avenue Batetela, Gombe, Kinshasa',
            ]]);
        });

        $position = app(Nominatim::class)->chercher(
            '9257 Av. Batetela ( galerie la fontaine )',
            'Gombe Kinshasa',
        );

        $this->assertNotNull($position, 'le second palier aurait du trouver');
        $this->assertEqualsWithDelta(-4.3163, $position['lat'], 0.0001);

        $this->assertCount(2, $appels, 'deux paliers, pas un de plus');

        // Le numero, les parentheses et l'abreviation ont disparu du second.
        $this->assertStringNotContainsString('9257', $appels[1]);
        $this->assertStringNotContainsString('galerie', $appels[1]);
        $this->assertStringContainsString('Avenue Batetela', $appels[1]);
    }

    /**
     * Sans pays configure, on cherche partout.
     *
     * Le jour ou le service ouvre hors de RDC, vider la variable suffit : le
     * parametre disparait de la requete au lieu d'etre envoye vide, ce que
     * Nominatim interpreterait comme « aucun pays ne convient ».
     */
    public function test_sans_pays_configure_la_recherche_est_mondiale(): void
    {
        config(['geocodage.pays' => '']);

        Http::fake(['*' => Http::response([['lat' => '48.85', 'lon' => '2.35']])]);

        app(Nominatim::class)->chercher('Avenue des Champs-Elysees');

        Http::assertSent(fn ($requete): bool => ! str_contains($requete->url(), 'countrycodes'));
    }

    /** Une adresse deja propre n'est interrogee qu'UNE fois. */
    public function test_une_adresse_propre_n_est_pas_reessayee(): void
    {
        Http::fake(['nominatim.openstreetmap.org/*' => Http::response([[
            'lat' => '-4.3', 'lon' => '15.3',
        ]])]);

        app(Nominatim::class)->chercher('Avenue Batetela', 'Gombe Kinshasa');

        Http::assertSentCount(1);
    }
}
