<?php

namespace Tests\Feature\Filament;

use App\Filament\Pages\Auth\Connexion;
use Filament\Facades\Filament;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La marque dans l'administration, et le menage sur le tableau de bord.
 *
 * « dans l'admin tu ajoutes le logo et tu ameliores egalement l'ecran de
 * connexion. tu supprimes le widget de filament. »
 */
class MarqueAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_le_panneau_porte_le_logo(): void
    {
        $panneau = Filament::getPanel('admin');

        $this->assertStringContainsString('logo-thalia.png', (string) $panneau->getBrandLogo());
        $this->assertSame('Thalia', $panneau->getBrandName());
    }

    /**
     * Les deux widgets livres avec Filament ne sont plus la.
     *
     * Le premier repetait le nom du compte, deja dans le coin haut droit ; le
     * second affichait la version du cadriciel. Ils poussaient les chiffres du
     * jour sous le pli.
     */
    public function test_les_widgets_de_filament_sont_retires(): void
    {
        $widgets = Filament::getPanel('admin')->getWidgets();

        $this->assertNotContains(AccountWidget::class, $widgets);
        $this->assertNotContains(FilamentInfoWidget::class, $widgets);
    }

    /**
     * LA PAGE DE PROFIL GARDE LE MENU.
     *
     * Filament la rend par defaut en « simple page » : une carte seule, hors
     * de la coquille du panneau, sans barre laterale. Le proprietaire, le 17
     * octobre : « /admin/profile impossible de revenir sur le menu ». On y
     * entrait sans pouvoir en sortir.
     */
    public function test_la_page_de_profil_reste_dans_le_panneau(): void
    {
        $this->assertFalse(Filament::getPanel('admin')->isProfilePageSimple());
    }

    public function test_l_ecran_de_connexion_est_le_notre_et_en_francais(): void
    {
        $this->assertSame(Connexion::class, Filament::getPanel('admin')->getLoginRouteAction());

        $this->get('/admin/login')
            ->assertSuccessful()
            ->assertSee('Administration Thalia')
            ->assertSee('logo-thalia.png', escape: false)
            ->assertDontSee('Sign in');
    }
}
