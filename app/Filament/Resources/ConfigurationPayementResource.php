<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use App\Filament\Resources\ConfigurationPayementResource\Pages\ListConfigurationPayements;
use App\Filament\Resources\ConfigurationPayementResource\Pages\CreateConfigurationPayement;
use App\Filament\Resources\ConfigurationPayementResource\Pages\EditConfigurationPayement;
use App\Filament\Resources\ConfigurationPayementResource\Pages;
use App\Filament\Resources\ConfigurationPayementResource\RelationManagers;
use App\Models\ConfigurationPayement;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ConfigurationPayementResource extends Resource
{
    protected static ?string $model = ConfigurationPayement::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getNavigationGroup(): ?string
    {
        return 'System Tools';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('token')
                    ->required()
                    ->columnSpanFull(),
                Textarea::make('token_key')
                    ->required()
                    ->columnSpanFull(),
                Toggle::make('active')
                    ->required(),
                TextInput::make('environment')
                    ->required()
                    ->maxLength(255)
                    ->default('production'),
                TextInput::make('url')
                    ->maxLength(255),
                Textarea::make('url_doc')
                    ->columnSpanFull(),
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('url')
                    ->label('Title')
                    ->searchable(),
                ToggleColumn::make('active')
                   ,
                TextColumn::make('environment')
                    ->searchable(),

                TextColumn::make('user.name')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                //Tables\Actions\EditAction::make(),
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
            'index' => ListConfigurationPayements::route('/'),
            'create' => CreateConfigurationPayement::route('/create'),
            'edit' => EditConfigurationPayement::route('/{record}/edit'),
        ];
    }
}
