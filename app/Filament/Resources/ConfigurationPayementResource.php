<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ConfigurationPayementResource\Pages;
use App\Filament\Resources\ConfigurationPayementResource\RelationManagers;
use App\Models\ConfigurationPayement;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ConfigurationPayementResource extends Resource
{
    protected static ?string $model = ConfigurationPayement::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function getNavigationGroup(): ?string
    {
        return 'System Tools';
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Textarea::make('token')
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('token_key')
                    ->required()
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('active')
                    ->required(),
                Forms\Components\TextInput::make('environment')
                    ->required()
                    ->maxLength(255)
                    ->default('production'),
                Forms\Components\TextInput::make('url')
                    ->maxLength(255),
                Forms\Components\Textarea::make('url_doc')
                    ->columnSpanFull(),
                Forms\Components\Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('url')
                    ->label('Title')
                    ->searchable(),
                Tables\Columns\ToggleColumn::make('active')
                   ,
                Tables\Columns\TextColumn::make('environment')
                    ->searchable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('deleted_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
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
                //Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
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
            'index' => Pages\ListConfigurationPayements::route('/'),
            'create' => Pages\CreateConfigurationPayement::route('/create'),
            'edit' => Pages\EditConfigurationPayement::route('/{record}/edit'),
        ];
    }
}
