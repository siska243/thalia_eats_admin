<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class HealthCheckResults extends Page
{
    protected static ?string $navigationLabel = 'État du système';

    protected static ?int $navigationSort = 9;

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-text';

    protected string $view = 'filament.pages.health-check-results';

    /*
     * Visible dans le menu, sous Configuration.
     *
     * Elle etait masquee : une page de sante qu'on ne trouve pas ne sert a
     * personne le jour ou l'application tousse. Aucun risque de doublon — le
     * plugin se contente d'enregistrer la page, c'est elle qui decide de son
     * entree de menu.
     */
    protected static bool $shouldRegisterNavigation = true;

    public $record=null;
    public static function getNavigationGroup(): ?string
    {
        return 'System Tools';
    }

    public function mount(): void
    {

    }
}
