<?php

namespace App\Providers\Filament;

use App\Http\Middleware\SetAdminLocale;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Dashboard;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use App\Filament\Pages\HealthCheckResults;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
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
            ->login()
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
                AccountWidget::class,
                FilamentInfoWidget::class,
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
                    'lg' => 3
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
                //->usingPage(\ShuvroRoy\FilamentSpatieLaravelHealth\Pages\HealthCheckResults::class),
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
