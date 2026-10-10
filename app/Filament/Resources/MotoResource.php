<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\IconColumn;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\CreateAction;
use App\Filament\Resources\MotoResource\Pages\ListMotos;
use App\Filament\Resources\MotoResource\Pages\CreateMoto;
use App\Filament\Resources\MotoResource\Pages\EditMoto;
use App\Filament\Resources\MotoResource\Pages;
use App\Filament\Resources\MotoResource\RelationManagers;
use App\Models\Moto;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class MotoResource extends Resource
{
    protected static ?string $model = Moto::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-cube-transparent';
    protected static string | \UnitEnum | null $navigationGroup="Livraison";

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('delivrery_driver_id')
                    ->required()
                    ->numeric(),
                Textarea::make('picture')
                    ->columnSpanFull(),
                Toggle::make('is_verified')
                    ->required(),
                TextInput::make('matricule')
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('delivrery_driver_id')
                    ->numeric()
                    ->sortable(),
                IconColumn::make('is_verified')
                    ->boolean(),
                TextColumn::make('matricule')
                    ->searchable(),
                TextColumn::make('slug')
                    ->searchable(),
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
            'index' => ListMotos::route('/'),
            'create' => CreateMoto::route('/create'),
            'edit' => EditMoto::route('/{record}/edit'),
        ];
    }
}
