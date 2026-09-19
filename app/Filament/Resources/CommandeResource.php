<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\DateTimePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\ViewAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\CreateAction;
use Filament\Pages\Enums\SubNavigationPosition;
use App\Filament\Resources\CommandeResource\Pages\ViewCommande;
use App\Filament\Resources\CommandeResource\Pages\ManageCommandeProducts;
use App\Filament\Resources\CommandeResource\Pages\ListCommandes;
use App\Filament\Resources\CommandeResource\Pages\EditCommande;
use App\Filament\Resources\CommandeResource\Pages;
use App\Filament\Resources\CommandeResource\RelationManagers;
use App\Models\Commande;
use Filament\Forms;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;

class CommandeResource extends Resource
{
    protected static ?string $model = Commande::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shopping-bag';
    protected static string | \UnitEnum | null $navigationGroup = "Thalia eats";
    protected static ?string $navigationModeleLabel = "Commande";

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
                        TextInput::make('adresse_delivery')
                        ,
                        TextInput::make('street')
                        ,
                        TextInput::make('number_street')
                        ,

                        Select::make('town_id')
                            ->relationship('town', 'title'),

                        DateTimePicker::make('cancel_at'),
                        DateTimePicker::make('delivery_at'),
                        DateTimePicker::make('paied_at'),
                    ])

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('user.name'),
                TextColumn::make('status')
                    ->formatStateUsing(fn($state) => new HtmlString("<div class='flex gap-1'>
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
                    ->label(__("Livreur"))
                    ->searchable()
                ,
                TextColumn::make('adresse_delivery')
                    ->sortable(),

                TextColumn::make('street')
                    ->toggleable(isToggledHiddenByDefault: true)
                ,
                TextColumn::make('number_street')
                    ->toggleable(isToggledHiddenByDefault: true)
                ,
                TextColumn::make('town.title')
                    ->toggleable(isToggledHiddenByDefault: true)

                ,
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

    protected static ?\Filament\Pages\Enums\SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;


    public static function getRecordSubNavigation(\Filament\Pages\Page $page): array
    {
        return $page->generateNavigationItems([
            // ...
            ViewCommande::class,
            ManageCommandeProducts::class

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
