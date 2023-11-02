<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DelivreryPriceResource\Pages;
use App\Filament\Resources\DelivreryPriceResource\RelationManagers;
use App\Models\DelivreryPrice;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DelivreryPriceResource extends Resource
{
    protected static ?string $model = DelivreryPrice::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('town_id')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('interval_pricing')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('frais')
                    ->required()
                    ->numeric(),
                Forms\Components\TextInput::make('currency_id')
                    ->required()
                    ->numeric(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('town_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('interval_pricing')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('frais')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('currency_id')
                    ->numeric()
                    ->sortable(),
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
