<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GerantResource\Pages\ListGerants;
use App\Models\User;
use Filament\Actions\BulkActionGroup;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Les gerants de restaurant, vus d'un seul ecran.
 *
 * Un gerant est ici **celui qui porte au moins un restaurant**, et rien
 * d'autre. Trois definitions cohabitent en base et se contredisent :
 * `users.type_user = 'restaurant'`, le role Spatie `restaurant`, et la
 * propriete reelle d'un restaurant. Seule la derniere decide de ce qu'un
 * gerant voit dans l'application — `RestaurantController` resout son
 * perimetre par `restaurants.user_id` — donc c'est elle qui fait foi ici.
 *
 * L'ecran est en lecture seule : un gerant se cree dans Utilisateurs, et le
 * rattachement d'un restaurant se fait sur la fiche du restaurant. Deux
 * chemins d'ecriture pour la meme chose finiraient par diverger.
 */
class GerantResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $slug = 'gerants';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static string|\UnitEnum|null $navigationGroup = 'Restauration';

    protected static ?int $navigationSort = 6;

    protected static ?string $label = 'Gérant';

    protected static ?string $pluralLabel = 'Gérants';

    protected static ?string $modelLabel = 'Gérant';

    protected static ?string $pluralModelLabel = 'Gérants';

    protected static ?string $navigationLabel = 'Gérants';

    /** Seuls ceux qui portent un restaurant. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('restaurant')
            ->with('restaurant.town.city')
            ->withCount('restaurant');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Gérant')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('email')
                    ->label('Adresse e-mail')
                    ->searchable()
                    ->copyable(),

                TextColumn::make('phone')
                    ->label('Téléphone')
                    ->searchable(),

                /*
                 * Le nombre d'etablissements, en evidence.
                 *
                 * C'est l'information qui manquait : un gerant a plusieurs
                 * succursales et voit les commandes de toutes. Savoir
                 * lesquelles depend de cette colonne.
                 */
                TextColumn::make('restaurant_count')
                    ->label('Établissements')
                    ->badge()
                    ->sortable(),

                TextColumn::make('restaurant.name')
                    ->label('Ses restaurants')
                    ->badge()
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->expandableLimitedList(),

                TextColumn::make('restaurant.town.city.title')
                    ->label('Villes')
                    ->badge()
                    ->listWithLineBreaks()
                    ->placeholder('Non renseignée'),
            ])
            ->defaultSort('restaurant_count', 'desc')
            ->filters([
                SelectFilter::make('ville')
                    ->label('Ville')
                    ->relationship('restaurant.town.city', 'title')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([])
            ->toolbarActions([
                BulkActionGroup::make([]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGerants::route('/'),
        ];
    }

    /** Lecture seule : la creation passe par Utilisateurs. */
    public static function canCreate(): bool
    {
        return false;
    }
}
