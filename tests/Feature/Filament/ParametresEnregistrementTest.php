<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Parametres;
use App\Models\User;
use App\Settings\ProspectionSettings;
use App\Settings\RentalSettings;
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
