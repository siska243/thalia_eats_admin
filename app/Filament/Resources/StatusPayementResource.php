<?php

namespace App\Filament\Resources;

use App\Filament\Resources\StatusPayementResource\Pages\CreateStatusPayement;
use App\Filament\Resources\StatusPayementResource\Pages\EditStatusPayement;
use App\Filament\Resources\StatusPayementResource\Pages\ListStatusPayements;
use App\Models\StatusPayement;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class StatusPayementResource extends Resource
{
    protected static ?string $modelLabel = 'Statut de paiement';

    protected static ?string $pluralModelLabel = 'Statuts de paiement';

    protected static ?string $navigationLabel = 'Statuts de paiement';

    protected static ?int $navigationSort = 7;

    protected static ?string $model = StatusPayement::class;

    protected static string|\UnitEnum|null $navigationGroup = 'Paramètres';

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('code')
                    ->required()
                    ->maxLength(255),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('description')
                    ->maxLength(255),
                TextInput::make('color')
                    ->maxLength(255),
                Toggle::make('is_default')
                    ->required(),
                Toggle::make('is_paid')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('description')
                    ->searchable(),
                TextColumn::make('color')
                    ->searchable(),
                IconColumn::make('is_default')
                    ->boolean(),
                IconColumn::make('is_paid')
                    ->boolean(),
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
            'index' => ListStatusPayements::route('/'),
            'create' => CreateStatusPayement::route('/create'),
            'edit' => EditStatusPayement::route('/{record}/edit'),
        ];
    }
}
