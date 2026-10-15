<?php

namespace App\Filament\Pages;

use App\Settings\FoodSettings;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Les regles de livraison des repas.
 *
 * Le rayon et le message vivaient deja dans les reglages depuis la limite de
 * distance, mais aucun ecran ne les exposait : seul le rayon par ville etait
 * modifiable. Ils arrivent ici avec l'option de position.
 */
class ManageFoodSettings extends SettingsPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static string|\UnitEnum|null $navigationGroup = 'Parametre';

    protected static ?string $title = 'Livraison des repas';

    protected static ?string $navigationLabel = 'Livraison des repas';

    protected static string $settings = FoodSettings::class;

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Distance de livraison')
                ->description("Au-dela de cette distance entre le restaurant et l'adresse de livraison, la commande est refusee. Une ville peut resserrer ce rayon depuis sa propre fiche.")
                ->schema([
                    TextInput::make('max_delivery_radius_km')
                        ->label('Rayon maximal')
                        ->suffix('km')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(500)
                        ->required()
                        ->helperText("S'applique aux villes qui n'ont pas leur propre rayon."),

                    Textarea::make('out_of_radius_message')
                        ->label('Message de refus')
                        ->rows(3)
                        ->required()
                        ->helperText('Trois emplacements disponibles : :restaurant, :distance et :rayon. Ils sont remplaces par le nom du restaurant, la distance reelle et le rayon applique.')
                        ->columnSpanFull(),
                ])->columns(2),

            Section::make('Position du client')
                ->description("Par defaut, seule l'adresse de livraison compte : on peut commander pour sa famille a Kinshasa depuis n'importe ou.")
                ->schema([
                    Toggle::make('use_client_position')
                        ->label('Tenir compte de la position du client')
                        ->helperText("Active, un client doit aussi se trouver a portee du restaurant pour commander : depuis l'etranger, les restaurants de Kinshasa apparaissent grises. L'application lui demande alors sa position ; s'il refuse, seule l'adresse de livraison compte."),
                ]),
        ]);
    }
}
