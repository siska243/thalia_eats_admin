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
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class CommandeResource extends Resource
{
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
                    ->formatStateUsing(fn ($state) => new HtmlString("<div class='flex gap-1'>
<div class='w-5 h-5 rounded' style='background:$state->color'></div>
<div>{$state->title}</div>
</div>"))
                    ->searchable(),
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
                //
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
