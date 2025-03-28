<?php

namespace App\Filament\Resources;

use App\Enums\Device;
use App\Enums\MobilePermissions;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $label = "Nos utilisateurs";
    protected static ?string $navigationGroup = "Thalia eats";

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make()
                    ->columns(2)
                    ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('last_name')->required(),
                    TextInput::make('principal_adresse')->label('Principal adresse'),
                    Select::make('town_id')->label('Commune')
                        ->relationship('town', 'title')->searchable()->preload(),
                    Forms\Components\TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('password')
                        ->password()
                        ->required()
                        ->maxLength(255)
                        ->hidden(fn($operation) => $operation == "edit"),
                    Forms\Components\Select::make('type_user')->label('Type de compte')
                        ->options([
                            'drivers' => 'Livreur',
                            'restaurant' => 'Restaurant',
                            'interne'=>"Interne"
                        ])
                        ->required(),

                    Forms\Components\TextInput::make('phone')
                        ->tel()
                        ->maxLength(255),
                        Forms\Components\Fieldset::make('Permissions')
                            ->label(__('filament-shield::filament-shield.column.permissions'))
                            ->extraAttributes(['class' => 'text-primary-600', 'style' => 'border-color:var(--primary)'])
                            ->schema([
                                Forms\Components\Select::make('roles')
                                    ->multiple()
                                    ->preload()
                                    ->extraAttributes(['class' => 'text-primary-600'])
                                    ->relationship('roles', 'name'),
                                Select::make('devices')
                                    ->multiple()
                                    ->preload()
                                    ->extraAttributes(['class' => 'text-primary-600'])
                                ->options(Device::getOptions())
                                ,

                                Select::make('mobile_permissions')
                                    ->multiple()
                                    ->preload()
                                    ->extraAttributes(['class' => 'text-primary-600'])
                                ->options(MobilePermissions::getOptions()),


                            ])->columns(3),


                ])
            ]);

    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('email')
                    ->searchable(),

                Tables\Columns\TextColumn::make('type_user')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('phone')
                    ->searchable(),
                Tables\Columns\TagsColumn::make('roles.name'),
                Tables\Columns\TextColumn::make('email_verified_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                ,
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                ,
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
