<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Connexion;
use App\Filament\Pages\HealthCheckResults;
use App\Filament\Widgets\CeQuiBloque;
use App\Filament\Widgets\CommandesParJour;
use App\Filament\Widgets\DernieresCommandes;
use App\Filament\Widgets\StatistiquesDuJour;
use App\Http\Middleware\SetAdminLocale;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\MultiFactor\Email\EmailAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Rupadana\ApiService\ApiServicePlugin;
use ShuvroRoy\FilamentSpatieLaravelHealth\FilamentSpatieLaravelHealthPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Connexion::class)
            /*
             * La marque, au lieu du mot « Admin » en gras.
             *
             * Le panneau n'affichait aucun logo : rien, sur l'ecran de
             * connexion comme dans la barre laterale, ne disait de quelle
             * maison il s'agit. Le fichier est celui du site, recopie ici —
             * les trois depots sont separes, il n'y a pas d'assets partages.
             *
             * `brandLogoHeight` compte : l'image est carree en 1080 px, et
             * sans hauteur imposee elle remplirait l'en-tete.
             */
            ->brandName('Thalia')
            ->brandLogo(asset('assets/logo-thalia.png'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('assets/logo-thalia.png'))
            /*
             * La double authentification, LAISSEE AU CHOIX.
             *
             * `isRequired` reste a faux : personne n'est enrole d'office, et
             * la connexion de ceux qui n'activent rien ne change pas d'un
             * ecran. Le proprietaire : « c'est a l'utilisateur de choisir ».
             *
             * Les deux facteurs sont proposes. L'application
             * d'authentification est la plus solide — elle ne depend ni du
             * reseau ni de la messagerie, ce qui compte a Kinshasa — et
             * `recoverable()` donne les codes de secours, sans quoi un
             * telephone perdu ferme le compte pour de bon. Le courriel reste
             * la pour qui n'installe pas d'application.
             *
             * Aucune dependance ajoutee : `pragmarx/google2fa-qrcode` et
             * `chillerlan/php-qrcode` arrivent deja avec Filament, et le QR
             * se genere sans `bacon/bacon-qr-code`.
             */
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable(),
                EmailAuthentication::make(),
            ], isRequired: false)
            /*
             * Et l'ecran ou on l'active. Sans page de profil, les deux
             * facteurs seraient configures nulle part.
             *
             * `isSimple: false` N'EST PAS UN DETAIL : par defaut Filament
             * rend cette page en « simple page », c'est-a-dire une carte
             * seule, hors de la coquille du panneau — sans barre laterale ni
             * en-tete. On y entre et on ne peut plus en sortir autrement
             * qu'en modifiant l'adresse. Avec `false`, elle s'affiche comme
             * n'importe quel ecran d'administration, et le menu reste la.
             */
            ->profile(isSimple: false)
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                /*
                 * Les widgets metier d'abord : en ouvrant l'admin, on cherche
                 * l'etat du commerce, pas la version du cadriciel. Chacun
                 * porte son propre garde de permission — `define_via_gate` est
                 * a false, super_admin ne contourne rien.
                 */
                StatistiquesDuJour::class,
                CeQuiBloque::class,
                CommandesParJour::class,
                DernieresCommandes::class,
                /*
                 * AccountWidget et FilamentInfoWidget sont RETIRES.
                 *
                 * Le premier repetait le nom du compte, deja dans le coin
                 * haut droit ; le second affichait la version du cadriciel et
                 * un lien vers sa documentation — une information pour nous,
                 * sur un ecran qui appartient au commerce. Ils poussaient les
                 * chiffres du jour sous le pli.
                 */
            ])
            ->databaseNotificationsPolling(2)
            ->databaseNotifications()
            ->maxContentWidth('full')
            ->middleware([
                SetAdminLocale::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])->plugins([
                FilamentShieldPlugin::make()
                    ->gridColumns([
                        'default' => 1,
                        'sm' => 2,
                        'lg' => 3,
                    ])
                    ->sectionColumnSpan(1)
                    ->checkboxListColumns([
                        'default' => 1,
                        'sm' => 2,
                        'lg' => 4,
                    ])
                    ->resourceCheckboxListColumns([
                        'default' => 1,
                        'sm' => 2,
                    ]),
                ApiServicePlugin::make(),
                FilamentSpatieLaravelHealthPlugin::make()
                // ->usingPage(\ShuvroRoy\FilamentSpatieLaravelHealth\Pages\HealthCheckResults::class),
                    ->usingPage(HealthCheckResults::class),
            ])
            /*
             * saade/filament-laravel-log est retire : aucune version ne suit
             * Filament 5, il plafonne a ^4.0. La page « Logs » disparaissait
             * donc du menu, alors que opcodesio/log-viewer — deja en
             * dependance directe — sert les journaux sur sa propre route.
             *
             * L'entree est rendue au menu, au meme endroit et sous le meme
             * libelle : la consultation des journaux ne change pas de place
             * pour ceux qui s'en servent. Elle ouvre un nouvel onglet parce
             * que la visionneuse a sa propre interface, hors du panneau.
             */
            ->navigationItems([
                NavigationItem::make('Logs')
                    ->url('/log-viewer', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-bug-ant')
                    ->group('System Tools')
                    ->sort(1),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
