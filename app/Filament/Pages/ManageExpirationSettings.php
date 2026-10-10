<?php

namespace App\Filament\Pages;

use App\Settings\ExpirationSettings;
use Filament\Forms\Components\TextInput;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Le delai de reglement, commun aux repas et a la location.
 *
 * C'est le temps maximum pendant lequel un client reste bloque par une ligne
 * qu'il a abandonnee : il ne peut avoir qu'une commande et qu'une course non
 * reglees a la fois. Le bon chiffre est commercial, il s'ajuste en regardant
 * les abandons reels — pas en editant un fichier et en redeployant.
 */
class ManageExpirationSettings extends SettingsPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-clock';

    protected static string|\UnitEnum|null $navigationGroup = 'Parametre';

    protected static ?string $title = "Délai d'expiration";

    protected static ?string $navigationLabel = "Délai d'expiration";

    protected static string $settings = ExpirationSettings::class;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Règlement')
                ->description('Vaut pour les commandes de repas comme pour les réservations de véhicule : la règle annoncée au client est la même des deux côtés.')
                ->schema([
                    TextInput::make('minutes')
                        ->label('Délai accordé pour régler')
                        ->suffix('minutes')
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->required()
                        ->helperText("Passé ce délai, la commande ou la réservation non payée expire et cesse de bloquer le client. Le compte à rebours affiché dans l'application suit cette valeur."),
                ]),
        ]);
    }
}
