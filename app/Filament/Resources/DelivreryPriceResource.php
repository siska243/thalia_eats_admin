<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\CreateAction;
use App\Filament\Resources\DelivreryPriceResource\Pages\ListDelivreryPrices;
use App\Filament\Resources\DelivreryPriceResource\Pages\CreateDelivreryPrice;
use App\Filament\Resources\DelivreryPriceResource\Pages\EditDelivreryPrice;
use App\Filament\Resources\DelivreryPriceResource\Pages;
use App\Filament\Resources\DelivreryPriceResource\RelationManagers;
use App\Models\DelivreryPrice;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DelivreryPriceResource extends Resource
{
    protected static ?string $model = DelivreryPrice::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-banknotes';
    protected static string | \UnitEnum | null $navigationGroup = "Livraison";

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('town_id')
                    ->required()
                    ->preload()
                    ->searchable()
                    ->relationship('town', 'title'),
                TextInput::make('interval_pricing')
                    ->required()
                    ->numeric(),

                TextInput::make('interval_max_price')
                    ->required()
                    ->numeric(),
                TextInput::make('frais')->label('Frais Livraison')
                    ->required()
                    ->numeric(),
                TextInput::make('service_price')->label('Frais service')
                    ->required()
                    ->numeric(),
                Select::make('currency_id')
                    ->relationship('currency', 'title')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('town.title')->label('Commune')
                    ->badge()
                    ->searchable()
                    ->numeric()
                    ->sortable(),
                TextColumn::make('interval_pricing')
                    ->label('Interval prix minimum')
                    ->numeric()
                    ->sortable(),

                TextColumn::make('interval_max_price')
                    ->label('Interval prix maximum')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('frais')->label('Frais livraison')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('service_price')->label('Frais service')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('currency.title')->label('Devise')
                    ->numeric()
                    ->sortable(),
                ToggleColumn::make('is_active'),
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
            'index' => ListDelivreryPrices::route('/'),
            'create' => CreateDelivreryPrice::route('/create'),
            'edit' => EditDelivreryPrice::route('/{record}/edit'),
        ];
    }
}
