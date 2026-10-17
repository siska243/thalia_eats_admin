<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CityResource\Pages\CreateCity;
use App\Filament\Resources\CityResource\Pages\EditCity;
use App\Filament\Resources\CityResource\Pages\ListCities;
use App\Models\City;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class CityResource extends Resource
{
    protected static ?string $modelLabel = 'Ville';

    protected static ?string $pluralModelLabel = 'Villes';

    protected static ?string $navigationLabel = 'Villes';

    protected static ?int $navigationSort = 4;

    protected static ?string $model = City::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Paramètres';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255),
                    Toggle::make('is_active')
                        ->required(),
                    /*
                     * Le rayon de livraison de cette ville.
                     *
                     * Vide, c'est le reglage global qui s'applique : une ville
                     * n'a pas forcement d'avis, et forcer une valeur ici aurait
                     * fige le global ville par ville.
                     *
                     * Minimum 1 : zero fermerait la ville entiere sur une faute
                     * de frappe. Pour fermer une ville, on la desactive.
                     */
                    TextInput::make('max_delivery_radius_km')
                        ->label('Rayon de livraison (km)')
                        ->helperText('Laisser vide pour appliquer le rayon global. Au-dela de cette distance, un client ne peut pas commander dans un restaurant de cette ville.')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(500),
                ])->columns(),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('slug')
                    ->searchable(),
                ToggleColumn::make('is_active'),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCities::route('/'),
            'create' => CreateCity::route('/create'),
            'edit' => EditCity::route('/{record}/edit'),
        ];
    }
}
