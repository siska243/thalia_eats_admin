<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Parametres;
use App\Models\User;
use App\Settings\ExpirationSettings;
use App\Settings\FoodSettings;
use App\Settings\ProspectionSettings;
use App\Settings\RentalSettings;
use App\Settings\SuiviSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Enregistrer les reglages depuis /admin/parametres.
 *
 * En production, la page s'ouvre mais le bouton Enregistrer rend 419. Livewire
 * masque deliberement la vraie erreur : un `\TypeError` leve pendant la mise a
 * jour du composant est journalise puis transforme en 419 generique
 * (`HandleRequests::handleUpdate`). Un 419 n'est donc PAS une preuve de session
 * perdue — c'est l'enveloppe d'une erreur de type.
 *
 * Ces tests ouvrent la page et l'enregistrent reellement : s'il existe un
 * chemin ou une valeur du formulaire n'a pas le type que la classe de reglages
 * declare, il remonte ici en clair au lieu de rendre 419 en production.
 */
class ParametresEnregistrementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Les cinq classes de reglages sont fausses.
     *
     * Une autre suite vide la table `settings` : sans ces faux, ces tests
     * passent seuls et tombent dans la suite complete. `loadMissingValues`
     * a faux impose d'enumerer chaque propriete — c'est voulu, une propriete
     * oubliee ici ressortirait en erreur plutot qu'en silence.
     */
    protected function setUp(): void
    {
        parent::setUp();

        FoodSettings::fake([
            'max_delivery_radius_km' => 30.0,
            'out_of_radius_message' => 'Trop loin.',
            'use_client_position' => false,
            'city_first' => false,
            'stock_actif' => true,
        ], loadMissingValues: false);

        RentalSettings::fake([
            'deposit_percentage' => 20.0,
            'refund_percentage' => 100.0,
            'minimum_hours' => 1.0,
            'pickup_code_max_attempts' => 3,
            'hours_min' => 1,
            'hours_max' => 23,
            'days_min' => 1,
            'days_max' => 30,
            'lead_time_minutes' => 60,
            'whatsapp_number' => '+243900000000',
            'brand_food' => 'Thalia Eats',
            'brand_rental' => 'Thalia Trans',
            'out_of_zone_message' => 'Hors zone.',
        ], loadMissingValues: false);

        ExpirationSettings::fake(['minutes' => 30], loadMissingValues: false);

        SuiviSettings::fake([
            'interval_minutes' => 1,
            'stale_minutes' => 10,
            'retention_days' => 30,
        ], loadMissingValues: false);

        ProspectionSettings::fake(
            ['email_destinataire' => 'contact@example.com'],
            loadMissingValues: false,
        );
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $role = Role::findOrCreate('admin-test', 'web');
        $role->givePermissionTo(Permission::findOrCreate('View:Parametres', 'web'));
        $admin->assignRole($role);
        $this->actingAs($admin, 'web');

        return $admin;
    }

    /** @test */
    public function enregistrer_sans_rien_changer_ne_leve_rien(): void
    {
        $this->admin();

        Livewire::test(Parametres::class)
            ->call('save')
            ->assertHasNoFormErrors();
    }

    /** @test */
    public function vider_un_champ_texte_facultatif_enregistre_au_lieu_de_rendre_419(): void
    {
        $this->admin();

        /*
         * Le cas exact de la production : le numero WhatsApp est facultatif,
         * Filament rend `null` un champ vide, et `RentalSettings::$whatsapp_number`
         * est declare `string`. Sans garde, `Settings::fill()` leve un TypeError
         * que Livewire transforme en 419 muet.
         */
        Livewire::test(Parametres::class)
            ->set('data.rental.whatsapp_number', '')
            ->set('data.prospection.email_destinataire', '')
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('', app(RentalSettings::class)->whatsapp_number);
        $this->assertSame('', app(ProspectionSettings::class)->email_destinataire);
    }

    /** @test */
    public function un_champ_numerique_vide_est_refuse_proprement(): void
    {
        $this->admin();

        /*
         * Les champs numeriques sont obligatoires : la validation doit les
         * arreter AVANT `Settings::fill()`, avec un message lisible plutot
         * qu'un 419.
         */
        Livewire::test(Parametres::class)
            ->set('data.expiration.minutes', '')
            ->call('save')
            ->assertHasFormErrors(['expiration.minutes']);
    }
}
