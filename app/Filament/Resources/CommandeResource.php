<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CommandeResource\Pages;
use App\Filament\Resources\CommandeResource\RelationManagers;
use App\Models\Commande;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Pages\SubNavigationPosition;
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

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';
    protected static ?string $navigationGroup = "Thalia eats";
    protected static ?string $navigationModeleLabel = "Commande";

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->columns(3)
                    ->schema([
                        Forms\Components\Select::make('user_id')->relationship('user', 'name'),
                        Forms\Components\Select::make('status_id')
                            ->relationship('status', 'title'),
                        Forms\Components\TextInput::make('refernce')
                            ->required()
                            ->maxLength(255),
                        Forms\Components\TextInput::make('global_price')
                            ->numeric(),
                        Forms\Components\TextInput::make('price_delivery')
                            ->numeric(),
                        Forms\Components\TextInput::make('price_service')
                            ->numeric(),
                        Forms\Components\TextInput::make('delivrery_driver_id')
                            ->numeric(),
                        Forms\Components\TextInput::make('adresse_delivery')
                            ,
                        Forms\Components\TextInput::make('street')
                            ,
                        Forms\Components\TextInput::make('number_street')
                            ,

                        Forms\Components\Select::make('town_id')
                            ->relationship('town', 'title'),

                        Forms\Components\DateTimePicker::make('cancel_at'),
                        Forms\Components\DateTimePicker::make('delivery_at'),
                        Forms\Components\DateTimePicker::make('paied_at'),
                    ])

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                Tables\Columns\TextColumn::make('user.name'),
                Tables\Columns\TextColumn::make('status')
                    ->formatStateUsing(fn($state) => new HtmlString("<div class='flex gap-1'>
<div class='w-5 h-5 rounded' style='background:$state->color'></div>
<div>{$state->title}</div>
</div>"))
                    ->searchable(),
                Tables\Columns\TextColumn::make('refernce')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->searchable(),
                Tables\Columns\TextColumn::make('global_price')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('price_service')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('price_delivery')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('delivrery_driver_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('adresse_delivery')
                    ->sortable(),

                Tables\Columns\TextColumn::make('street')
                    ->toggleable(isToggledHiddenByDefault: true)
                ,
                Tables\Columns\TextColumn::make('number_street')
                    ->toggleable(isToggledHiddenByDefault: true)
                ,
                Tables\Columns\TextColumn::make('town.title')
                    ->toggleable(isToggledHiddenByDefault: true)

                ,
                Tables\Columns\TextColumn::make('cancel_at')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('delivery_at')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('paied_at')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('accepted_at')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateActions([
                Tables\Actions\CreateAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;


    public static function getRecordSubNavigation(\Filament\Pages\Page $page): array
    {
        return $page->generateNavigationItems([
            // ...
            Pages\ViewCommande::class,
            Pages\ManageCommandeProducts::class

        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCommandes::route('/'),
            'view' => Pages\ViewCommande::route('/{record}/view'),
            'edit' => Pages\EditCommande::route('/{record}/edit'),
            'commande_product' => Pages\ManageCommandeProducts::route('/{record}/commande-products'),
        ];
    }
}
