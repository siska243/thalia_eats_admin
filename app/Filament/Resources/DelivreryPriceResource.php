<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DelivreryPriceResource\Pages;
use App\Filament\Resources\DelivreryPriceResource\RelationManagers;
use App\Models\DelivreryPrice;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DelivreryPriceResource extends Resource
{
    protected static ?string $model = DelivreryPrice::class;

    protected static ?string $navigationIcon = 'heroicon-o-banknotes';
    protected static ?string $navigationGroup = "Livraison";

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Select::make('town_id')
                    ->required()
                    ->preload()
                    ->searchable()
                    ->relationship('town', 'title'),
                Forms\Components\TextInput::make('interval_pricing')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('frais')->label('Frais Livraison')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('service_price')->label('Frais service')
                    ->required()
                    ->numeric(),
                Forms\Components\Select::make('currency_id')
                    ->relationship('currency', 'title')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('town.title')->label('Commune')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('interval_pricing')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('frais')->label('Frais livraison')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('service_price')->label('Frais service')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('currency.title')->label('Devise')
                    ->numeric()
                    ->sortable(),
                ToggleColumn::make('is_active'),
                Tables\Columns\TextColumn::make('created_at')
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
                Tables\Actions\EditAction::make(),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDelivreryPrices::route('/'),
            'create' => Pages\CreateDelivreryPrice::route('/create'),
            'edit' => Pages\EditDelivreryPrice::route('/{record}/edit'),
        ];
    }
}
