<?php

namespace App\Filament\Pages;

use App\Settings\SuiviSettings;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Les reglages du suivi de position, repas et location confondus.
 *
 * Ils arbitrent entre la finesse du trace et le volume de la table. Les figer
 * dans le code obligerait a livrer pour changer une cadence, alors que c'est
 * precisement le genre de valeur qu'on ajuste en regardant les donnees.
 */
class ManageSuiviSettings extends SettingsPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static string|\UnitEnum|null $navigationGroup = 'Suivi des courses';

    protected static ?string $title = 'Réglages du suivi';

    protected static ?string $navigationLabel = 'Réglages';

    protected static ?int $navigationSort = 2;

    protected static string $settings = SuiviSettings::class;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Enregistrement des positions')
                ->description("Les applications émettent à leur rythme : c'est le serveur qui décide ce qui est conservé.")
                ->columns(2)
                ->schema([
                    TextInput::make('interval_minutes')
                        ->label('Intervalle entre deux relevés')
                        ->suffix('minutes')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->required()
                        ->helperText("Un point qui arrive plus tôt que ça est refusé. Descendre cette valeur affine le tracé et remplit la table d'autant plus vite."),

                    TextInput::make('stale_minutes')
                        ->label('Position considérée comme périmée')
                        ->suffix('minutes')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->required()
                        ->helperText("Au-delà de ce silence, le client ne voit plus de chauffeur sur sa carte. Montrer une position d'il y a une heure ferait attendre une voiture qui n'arrive pas."),

                    TextInput::make('retention_days')
                        ->label('Conservation des relevés')
                        ->suffix('jours')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->required()
                        ->helperText('Passé ce délai, les relevés sont supprimés chaque nuit. Exportez ce que vous voulez analyser avant.'),
                ]),
        ]);
    }
}
