<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TownResource\Pages\CreateTown;
use App\Filament\Resources\TownResource\Pages\EditTown;
use App\Filament\Resources\TownResource\Pages\ListTowns;
use App\Models\Town;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TownResource extends Resource
{
    protected static ?int $navigationSort = 3;

    protected static ?string $model = Town::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-flag';

    protected static ?string $label = 'Communes';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()->
                columns(2)
                    ->
                    schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255),
                        Select::make('city_id')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->relationship('city', 'title'),
                        TextInput::make('zip')
                            ->maxLength(255),
                        // La location ne couvre pas tout le pays : ouvrir une
                        // commune est une decision commerciale, prise ici
                        // plutot que dans une constante du code.
                        Toggle::make('rental_available')
                            ->label('Desservie par la location')
                            ->helperText('Hors de ces communes, le client est renvoye vers le service par WhatsApp.'),
                        /*
                         * Le centre approximatif de la commune.
                         *
                         * Il sert de position de repli quand un restaurant ou
                         * une adresse de livraison n'a pas ses propres
                         * coordonnees. Approximatif suffit : il separe Gombe de
                         * Lubumbashi, pas deux avenues voisines.
                         */
                        TextInput::make('latitude')
                            ->label('Latitude du centre')
                            ->helperText('Sert de position de repli pour les restaurants de cette commune sans coordonnees propres.')
                            ->numeric()
                            ->minValue(-90)
                            ->maxValue(90),
                        TextInput::make('longitude')
                            ->label('Longitude du centre')
                            ->numeric()
                            ->minValue(-180)
                            ->maxValue(180),
                    ]),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('zip')
                    ->searchable(),
                ToggleColumn::make('is_active'),
                // Cochable depuis la liste, comme `is_active` juste au-dessus :
                // Kinshasa compte vingt-quatre communes, et les ouvrir une a
                // une obligeait a vingt-quatre allers-retours dans le
                // formulaire.
                ToggleColumn::make('rental_available')
                    ->label('Location'),
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
                TernaryFilter::make('rental_available')
                    ->label('Desservie par la location'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateActions([
                CreateAction::make(),
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
            'index' => ListTowns::route('/'),
            'create' => CreateTown::route('/create'),
            'edit' => EditTown::route('/{record}/edit'),
        ];
    }
}
