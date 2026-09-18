<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Fieldset;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\TagsColumn;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\CreateAction;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Enums\Device;
use App\Enums\MobilePermissions;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Resources\UserResource\RelationManagers;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Hash;
use Rawilk\FilamentPasswordInput\Password;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-user-group';
    protected static ?string $label = "Nos utilisateurs";
    protected static string | \UnitEnum | null $navigationGroup = "Thalia eats";

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('last_name')->required(),
                        TextInput::make('principal_adresse')->label('Principal adresse'),
                        Select::make('town_id')->label('Commune')
                            ->relationship('town', 'title')->searchable()->preload(),
                        TextInput::make('email')
                            ->email()
                            ->required()
                            ->maxLength(255),
                        TextInput::make('password')
                            ->password()
                            ->required()
                            ->maxLength(255)
                            ->hidden(fn($operation) => $operation == "edit"),
                        Select::make('type_user')->label('Type de compte')
                            ->options([
                                'drivers' => 'Livreur',
                                'restaurant' => 'Restaurant',
                                'interne' => "Interne"
                            ])
                            ->required(fn($operation) => $operation == "create"),

                        TextInput::make('phone')
                            ->tel()
                            ->maxLength(255),
                        Fieldset::make('Permissions')
                            ->label(__('filament-shield::filament-shield.column.permissions'))
                            ->extraAttributes(['class' => 'text-primary-600', 'style' => 'border-color:var(--primary)'])
                            ->schema([
                                Select::make('roles')
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

                                Toggle::make('changePassword')
                                    ->label(__('Change password'))
                                    ->columnSpan(1)
                                    ->hiddenOn('create')
                                    ->reactive(),

                                // Password
                                Password::make('password')
                                    ->label(__('Password'))
                                    ->password()
                                    //->regex('/^(?=.*[A-Z])(?=.*[!@#$&*])(?=.*[0-9])(?=.*[a-z]).{8}/m')
                                    ->confirmed()
                                    ->autocomplete('password')
                                    ->required(fn(Get $get): bool => $get('changePassword'))
                                    ->dehydrateStateUsing(function ($state) {
                                        return Hash::make($state);
                                    })
                                    //->validationAttribute(__('password must contain at least 8 characters, have at least 1 uppercase, 1 lowercase, 1 number, 1 special character.'))
                                    ->hidden(fn(Get $get) => !$get('changePassword')),
                                Password::make('password_confirmation')
                                    ->label(__('Confirm Password'))
                                    ->password()
                                    ->hidden(fn(Get $get) => !$get('changePassword'))


                            ])->columns(3),


                    ])
            ]);

    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('email')
                    ->searchable(),

                TextColumn::make('type_user')
                    ->badge()
                    ->sortable(),
                TextColumn::make('phone')
                    ->searchable(),
                TagsColumn::make('roles.name'),
                TextColumn::make('email_verified_at')
                    ->dateTime()
                    ->toggleable(isToggledHiddenByDefault: true)
                    ->sortable(),
                TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                ,
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true)
                ,
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
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
