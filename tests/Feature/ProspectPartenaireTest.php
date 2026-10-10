<?php

namespace Tests\Feature;

use App\Mail\DemandePartenariat;
use App\Models\Prospect;
use App\Models\User;
use App\Settings\ProspectionSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Les demandes de partenariat venues du site.
 *
 * Les deux boutons de la page d'accueil pointaient sur `/` : ils promettaient
 * une suite qui n'existait pas. Ce que ces tests tiennent, c'est que la
 * demande arrive, qu'elle n'ouvre aucun acces, et qu'un e-mail qui echoue ne
 * fasse pas perdre le prospect.
 */
class ProspectPartenaireTest extends TestCase
{
    use RefreshDatabase;

    private function demandeRestaurant(array $overrides = []): array
    {
        return array_merge([
            'type' => 'restaurant',
            'responsable' => 'Maman Colonel',
            'telephone' => '+243810000001',
            'commune' => 'Gombe',
            'etablissement' => 'Chez Maman Colonel',
            'cuisine' => 'Congolaise',
        ], $overrides);
    }

    public function test_une_demande_restaurant_est_enregistree(): void
    {
        Mail::fake();

        $this->postJson('/api/prospects', $this->demandeRestaurant())->assertSuccessful();

        $prospect = Prospect::query()->firstOrFail();

        $this->assertSame(Prospect::TYPE_RESTAURANT, $prospect->type);
        $this->assertSame('Maman Colonel', $prospect->nom);
        $this->assertSame('Chez Maman Colonel', $prospect->details['etablissement']);
        $this->assertSame(Prospect::STATUT_NOUVELLE, $prospect->statut);
        $this->assertNull($prospect->contacte_at);
    }

    /** Une demande de livreur porte ses propres champs, pas ceux du restaurant. */
    public function test_une_demande_livreur_porte_ses_champs(): void
    {
        Mail::fake();

        $this->postJson('/api/prospects', [
            'type' => 'livreur',
            'responsable' => 'Jean Kabila',
            'telephone' => '+243810000002',
            'commune' => 'Limete',
            'vehicule' => 'Moto',
            'permis' => 'Oui',
            'disponibilite' => 'Temps plein',
        ])->assertSuccessful();

        $prospect = Prospect::query()->firstOrFail();

        $this->assertSame(Prospect::TYPE_LIVREUR, $prospect->type);
        $this->assertSame('Moto', $prospect->details['vehicule']);
        $this->assertArrayNotHasKey('cuisine', $prospect->details);
    }

    /**
     * Le nom et le telephone sont les deux seuls champs vraiment exiges.
     *
     * L'e-mail ne l'est pas : a Kinshasa le rappel se fait par telephone, et
     * l'exiger ferait perdre des restaurants qui n'en utilisent pas.
     */
    public function test_le_nom_et_le_telephone_sont_exiges(): void
    {
        $this->postJson('/api/prospects', ['type' => 'restaurant'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['responsable', 'telephone']);
    }

    public function test_un_metier_inconnu_est_refuse(): void
    {
        $this->postJson('/api/prospects', $this->demandeRestaurant(['type' => 'banque']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    /**
     * La demande se fait sans compte.
     *
     * Exiger une authentification pour dire « je voudrais travailler avec
     * vous » perdrait le restaurateur avant qu'il ait une raison de
     * s'inscrire. C'est la seule ecriture anonyme de cette API, et le test
     * fige ce choix pour qu'il ne se referme pas par inadvertance.
     */
    public function test_la_demande_se_fait_sans_compte(): void
    {
        Mail::fake();

        $this->postJson('/api/prospects', $this->demandeRestaurant())->assertSuccessful();

        $this->assertSame(1, Prospect::query()->count());
    }

    /** Aucun compte, aucun role, aucun acces ne doit naitre d'une demande. */
    public function test_une_demande_ne_cree_aucun_compte(): void
    {
        Mail::fake();

        $avant = User::query()->count();

        $this->postJson('/api/prospects', $this->demandeRestaurant([
            'email' => 'resto@exemple.cd',
        ]))->assertSuccessful();

        $this->assertSame($avant, User::query()->count());
    }

    public function test_l_equipe_est_prevenue_par_mail(): void
    {
        Mail::fake();
        ProspectionSettings::fake(['email_destinataire' => 'equipe@thalia.test']);

        $this->postJson('/api/prospects', $this->demandeRestaurant())->assertSuccessful();

        Mail::assertSent(DemandePartenariat::class);
    }

    /** Sans adresse reglee, aucun envoi, et la demande passe quand meme. */
    public function test_sans_destinataire_regle_aucun_mail_ne_part(): void
    {
        Mail::fake();
        ProspectionSettings::fake(['email_destinataire' => '']);

        $this->postJson('/api/prospects', $this->demandeRestaurant())->assertSuccessful();

        Mail::assertNothingSent();
        $this->assertSame(1, Prospect::query()->count());
    }

    /**
     * Une passerelle d'envoi en panne ne doit pas faire perdre le prospect.
     *
     * Sa demande est deja enregistree quand l'e-mail part : la laisser
     * echouer renverrait une erreur a quelqu'un dont la demande EST passee.
     */
    public function test_un_envoi_en_echec_ne_fait_pas_echouer_la_demande(): void
    {
        ProspectionSettings::fake(['email_destinataire' => 'equipe@thalia.test']);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $this->postJson('/api/prospects', $this->demandeRestaurant())->assertSuccessful();

        $this->assertSame(1, Prospect::query()->count());
    }

    /** Le premier changement de statut date le contact, sans qu'on y pense. */
    public function test_le_changement_de_statut_date_le_contact(): void
    {
        $prospect = Prospect::query()->create([
            'type' => Prospect::TYPE_LIVREUR,
            'nom' => 'Jean',
            'telephone' => '+243810000003',
        ]);

        $this->assertNull($prospect->contacte_at);

        $prospect->update(['statut' => Prospect::STATUT_CONTACTEE]);

        $this->assertNotNull($prospect->fresh()->contacte_at);
    }
}
