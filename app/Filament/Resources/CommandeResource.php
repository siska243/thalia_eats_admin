<?php

namespace App\Filament\Resources;

use App\Filament\Pages\SuiviDesCourses;
use App\Filament\Resources\CommandeResource\Pages\EditCommande;
use App\Filament\Resources\CommandeResource\Pages\ListCommandes;
use App\Filament\Resources\CommandeResource\Pages\ManageCommandeProducts;
use App\Filament\Resources\CommandeResource\Pages\ViewCommande;
use App\Models\Commande;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class CommandeResource extends Resource
{
    protected static ?string $modelLabel = 'Commande';

    protected static ?string $pluralModelLabel = 'Commandes';

    protected static ?string $navigationLabel = 'Commandes';

    protected static ?int $navigationSort = 1;

    protected static ?string $model = Commande::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shopping-bag';

    protected static string|\UnitEnum|null $navigationGroup = 'Restauration';

    protected static ?string $navigationModeleLabel = 'Commande';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(3)
                    ->schema([
                        Select::make('user_id')->relationship('user', 'name'),
                        Select::make('status_id')
                            ->relationship('status', 'title'),
                        TextInput::make('refernce')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('global_price')
                            ->numeric(),
                        TextInput::make('price_delivery')
                            ->numeric(),
                        TextInput::make('price_service')
                            ->numeric(),
                        TextInput::make('delivrery_driver_id')
                            ->numeric(),
                        TextInput::make('adresse_delivery'),
                        TextInput::make('street'),
                        TextInput::make('number_street'),

                        Select::make('town_id')
                            ->relationship('town', 'title'),

                        DateTimePicker::make('cancel_at'),
                        DateTimePicker::make('delivery_at'),
                        DateTimePicker::make('paied_at'),
                    ]),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            // Le bouton « Suivre » a besoin de savoir s'il y a quelque chose a
            // voir : un compte en une requete, pas un comptage par ligne.
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount('releves'))
            ->columns([
                TextColumn::make('user.name'),
                TextColumn::make('status')
                    ->label('Statut')
                    ->formatStateUsing(fn ($state) => new HtmlString("<div class='flex gap-1'>
<div class='w-5 h-5 rounded' style='background:$state->color'></div>
<div>{$state->title}</div>
</div>"))
                    /*
                     * `status` est une relation, pas une colonne : la table
                     * porte `status_id`. Un `searchable()` nu faisait donc
                     * chercher Filament dans une colonne `status` qui
                     * n'existe pas, et TOUTE recherche sur cet ecran
                     * repondait 500 — y compris celle sur la reference.
                     *
                     * La recherche porte donc sur le libelle du statut, qui
                     * est ce que l'ecran affiche.
                     */
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->whereHas('status', fn (Builder $statut) => $statut
                            ->where('title', 'like', "%{$search}%"))),
                TextColumn::make('refernce')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),

                TextColumn::make('code_confirmation')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),

                TextColumn::make('code_confirmation_restaurant')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
                TextColumn::make('global_price')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('price_service')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('price_delivery')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('delivrery_driver.user.name')
                    ->label(__('Livreur'))
                    ->searchable(),
                TextColumn::make('adresse_delivery')
                    ->sortable(),

                TextColumn::make('street')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('number_street')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('town.title')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cancel_at')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('delivery_at')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('paied_at')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('accepted_at')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('created_at')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                /*
                 * Le workflow est editable en administration : la liste des
                 * statuts se lit en base, elle n'est pas figee dans le code.
                 */
                SelectFilter::make('status_id')
                    ->label('Statut')
                    ->relationship('status', 'title')
                    ->multiple()
                    ->preload(),

                SelectFilter::make('town_id')
                    ->label('Commune')
                    ->relationship('town', 'title')
                    ->searchable()
                    ->preload(),

                Filter::make('periode')
                    ->schema([
                        DatePicker::make('du')->label('Du'),
                        DatePicker::make('au')->label('Au'),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['du'] ?? null, fn (Builder $q, $date) => $q
                            ->whereDate('created_at', '>=', $date))
                        ->when($data['au'] ?? null, fn (Builder $q, $date) => $q
                            ->whereDate('created_at', '<=', $date))),
            ])
            ->recordActions([
                ViewAction::make(),

                Action::make('suivre')
                    ->label('Suivre')
                    ->icon('heroicon-o-map-pin')
                    ->color('info')
                    // Meme bouton que sur les reservations : le suivi s'ouvre
                    // depuis la ligne de la commande, pas depuis un annuaire.
                    ->visible(fn () => SuiviDesCourses::canAccess())
                    ->disabled(fn (Commande $record) => ! $record->releves_count)
                    ->tooltip(fn (Commande $record) => $record->releves_count
                        ? null
                        : 'Aucune position relevée pour cette commande : il n\'y a rien à afficher sur la carte.')
                    ->url(fn (Commande $record) => SuiviDesCourses::urlPour($record)),

                /*
                 * ANNULER, et seulement tant que rien n'est encaisse.
                 *
                 * `STATUTS_NON_REGLES` est la liste deja utilisee par les
                 * scopes du modele et par les commandes d'expiration : « en
                 * attente » et « en attente de paiement ». L'ecrire une
                 * seconde fois ici, en clair, c'est la condamner a diverger
                 * le jour ou un statut s'ajoute.
                 *
                 * Rien d'autre n'est a ecrire : le crochet `saving` du
                 * modele pose `cancel_at` des que le statut passe a annule,
                 * et refuse l'incoherence inverse. Une seule definition.
                 */
                Action::make('annuler')
                    ->label('Annuler')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Commande $record) => in_array(
                        (int) $record->status_id,
                        Commande::STATUTS_NON_REGLES,
                        true
                    ))
                    // Une commande annulee ne se reactive pas depuis cet
                    // ecran : on demande confirmation avant, pas de regret
                    // apres.
                    ->requiresConfirmation()
                    ->modalHeading('Annuler cette commande ?')
                    ->modalDescription('Le client ne pourra plus la régler. Cette action ne se défait pas depuis cet écran.')
                    ->modalSubmitActionLabel('Annuler la commande')
                    ->action(function (Commande $record) {
                        $record->status_id = Commande::STATUT_ANNULEE;
                        $record->save();

                        Notification::make()
                            ->title('Commande annulée')
                            ->body("La commande {$record->refernce} est annulée.")
                            ->success()
                            ->send();
                    }),
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

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            // ...
            ViewCommande::class,
            ManageCommandeProducts::class,

        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCommandes::route('/'),
            'view' => ViewCommande::route('/{record}/view'),
            'edit' => EditCommande::route('/{record}/edit'),
            'commande_product' => ManageCommandeProducts::route('/{record}/commande-products'),
        ];
    }
}
