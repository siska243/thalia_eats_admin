<?php

namespace App\Filament\Resources;

use App\Filament\Resources\TownResource\Pages;
use App\Filament\Resources\TownResource\RelationManagers;
use App\Models\Town;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TownResource extends Resource
{
    protected static ?string $model = Town::class;

    protected static ?string $navigationIcon = 'heroicon-o-flag';

    protected static ?string $label = "Communes";

    protected static ?string $navigationGroup = "Parametre";
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()->
                    columns(2)
                ->
                schema([
                    Forms\Components\TextInput::make('title')
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('zip')
                        ->maxLength(255),
                ])

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->searchable(),
                Tables\Columns\TextColumn::make('zip')
                    ->searchable(),
                Tables\Columns\ToggleColumn::make('is_active')

                ,
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
            'index' => Pages\ListTowns::route('/'),
            'create' => Pages\CreateTown::route('/create'),
            'edit' => Pages\EditTown::route('/{record}/edit'),
        ];
    }
}
