<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CommandeResource\Pages;
use App\Filament\Resources\CommandeResource\RelationManagers;
use App\Models\Commande;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CommandeResource extends Resource
{
    protected static ?string $model = Commande::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('status_id')
                    ->numeric(),
                Forms\Components\TextInput::make('refernce')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('global_price')
                    ->numeric(),
                Forms\Components\TextInput::make('price_delivery')
                    ->numeric(),
                Forms\Components\TextInput::make('delivrery_driver_id')
                    ->numeric(),
                Forms\Components\DateTimePicker::make('cancel_at'),
                Forms\Components\DateTimePicker::make('delivery_at'),
                Forms\Components\DateTimePicker::make('paied_at'),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('status_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('refernce')
                    ->searchable(),
                Tables\Columns\TextColumn::make('global_price')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('price_delivery')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('delivrery_driver_id')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('cancel_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('delivery_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('paied_at')
                    ->dateTime()
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
            'index' => Pages\ListCommandes::route('/'),
            'create' => Pages\CreateCommande::route('/create'),
            'edit' => Pages\EditCommande::route('/{record}/edit'),
        ];
    }    
}
