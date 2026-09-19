<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;

class HealthCheckResults extends Page
{
    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-document-text';

    protected string $view = 'filament.pages.health-check-results';

    protected static bool $shouldRegisterNavigation=false;

    public $record=null;
    public static function getNavigationGroup(): ?string
    {
        return 'System Tools';
    }

    public function mount(): void
    {

    }
}
