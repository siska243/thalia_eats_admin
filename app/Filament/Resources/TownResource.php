<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\CreateAction;
use App\Filament\Resources\TownResource\Pages\ListTowns;
use App\Filament\Resources\TownResource\Pages\CreateTown;
use App\Filament\Resources\TownResource\Pages\EditTown;
use App\Filament\Resources\TownResource\Pages;
use App\Filament\Resources\TownResource\RelationManagers;
use App\Models\Town;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class TownResource extends Resource
{
    protected static ?string $model = Town::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-flag';

    protected static ?string $label = "Communes";

    protected static string | \UnitEnum | null $navigationGroup = "Parametre";

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()->
                columns(2)
                    ->
                    schema([
                        TextInput::make('title')
                            ->required()
                            ->maxLength(255),
                        Select::make('city_id')
                            ->required()
                            ->searchable()
                            ->preload()
                            ->relationship('city', 'title'),
                        TextInput::make('zip')
                            ->maxLength(255),
                    ])

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('zip')
                    ->searchable(),
                ToggleColumn::make('is_active')

                ,
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
            'index' => ListTowns::route('/'),
            'create' => CreateTown::route('/create'),
            'edit' => EditTown::route('/{record}/edit'),
        ];
    }
}
