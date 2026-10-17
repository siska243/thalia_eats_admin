<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\CommandeResource;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\VehicleResource;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Le menu de l'administration : ordre, francais, et les roles au bon endroit.
 *
 * Le proprietaire, le 17 octobre : « le menu tu l'ordonnes bien et ça doit
 * être en français (toute l'admin). tu mets rôle dans group paramètre ».
 */
class MenuAdminTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string> Les libelles des groupes, dans l'ordre du menu. */
    private function groupes(): array
    {
        return collect(Filament::getPanel('admin')->getNavigationGroups())
            // Declares en chaines, ils restent des chaines ; declares en
            // objets, il faut leur libelle. Les deux formes coexistent.
            ->map(fn ($groupe) => is_string($groupe) ? $groupe : $groupe->getLabel())
            ->values()
            ->all();
    }

    /**
     * L'ordre des groupes est DECLARE, et non laisse au hasard.
     *
     * Sans la liste, Filament les range dans l'ordre de decouverte des
     * ressources — alphabetique — et les reglages atterrissent au milieu.
     */
    public function test_les_groupes_sont_dans_l_ordre_voulu(): void
    {
        $groupes = $this->groupes();

        $this->assertSame([
            'Restauration',
            'Location',
            'Livraison',
            'Clients et partenaires',
            'Paramètres',
            'Outils système',
        ], $groupes);
    }

    /** Plus aucun groupe en anglais. */
    public function test_aucun_groupe_n_est_en_anglais(): void
    {
        $groupes = $this->groupes();

        $this->assertNotContains('Configuration', $groupes);
        $this->assertNotContains('System Tools', $groupes);
    }

    public function test_les_roles_sont_dans_les_parametres(): void
    {
        $this->assertSame('Paramètres', FilamentShieldPlugin::get()->getNavigationGroup());
        $this->assertSame('Rôles', FilamentShieldPlugin::get()->getNavigationLabel());
    }

    /**
     * Les ressources portent un nom francais.
     *
     * `modelLabel` compte autant que l'entree de menu : c'est lui qui nomme
     * la ressource dans les titres, les boutons et les notifications —
     * « Create Vehicle » au lieu de « Créer un véhicule ».
     */
    public function test_les_ressources_sont_nommees_en_francais(): void
    {
        $attendu = [
            CommandeResource::class => ['Commande', 'Commandes'],
            ProductResource::class => ['Plat', 'Plats'],
            VehicleResource::class => ['Véhicule', 'Véhicules'],
            UserResource::class => ['Utilisateur', 'Utilisateurs'],
        ];

        foreach ($attendu as $ressource => [$singulier, $pluriel]) {
            $this->assertSame($singulier, $ressource::getModelLabel(), $ressource);
            $this->assertSame($pluriel, $ressource::getPluralModelLabel(), $ressource);
        }
    }

    /** Aucune ressource ne reste sans libelle de navigation. */
    public function test_chaque_ressource_a_son_libelle(): void
    {
        foreach (Filament::getPanel('admin')->getResources() as $ressource) {
            $this->assertNotEmpty($ressource::getNavigationLabel(), $ressource);
        }
    }
}
