<?php

namespace App\Filament\Resources;

use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\CreateAction;
use Filament\Pages\Page;
use App\Filament\Resources\RestaurantResource\Pages\EditRestaurant;
use App\Filament\Resources\RestaurantResource\Pages\ManageProducts;
use App\Filament\Resources\RestaurantResource\Pages\ListRestaurants;
use App\Filament\Resources\RestaurantResource\Pages\CreateRestaurant;
use App\Filament\Resources\RestaurantResource\Pages;
use App\Filament\Resources\RestaurantResource\RelationManagers;
use App\Models\Restaurant;
use Filament\Forms\Components\{DatePicker, FileUpload, KeyValue, TextInput, Textarea, MarkdownEditor, Repeater, RichEditor, Section, Select, TimePicker};
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class RestaurantResource extends Resource
{
    protected static ?string $model = Restaurant::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-home-modern';

    protected static ?string $label = "Nos restaurants";
    protected static string | \UnitEnum | null $navigationGroup = "Thalia eats";
    protected static ?int $navigationSort = 2;
    protected static ?\Filament\Pages\Enums\SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                \Filament\Schemas\Components\Section::make('Information de base')->schema([
                    Select::make('user_id')
                        ->relationship('user', 'name')
                        ->native(false)
                        ->preload()
                        ->required()
                        ->searchable()->label('Responsable du restaurant'),
                    TextInput::make('name')
                        ->required()
                        ->label('Nom du restaurant')
                        ->maxLength(255),
                    TextInput::make('adresse')
                        ->required()
                        ->maxLength(65535),
                    Select::make('town_id')->relationship('town', 'title'),
                    TextInput::make('reference')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('phone')
                        ->tel()
                        ->required()
                        ->maxLength(255),
                    TextInput::make('whatsapp')
                        ->tel()
                        ->required(false)
                        ->maxLength(255),
                    TextInput::make('email')
                        ->email()
                        ->maxLength(50),
                        RichEditor::make('description')->columnSpanFull()
                ])->columns(2),
                \Filament\Schemas\Components\Section::make('Information supplementaire')->schema([
                    Repeater::make('openHours')->schema([
                        Select::make('day')
                            ->options([
                                'lundi' => 'Lundi',
                                'mardi' => 'Mardi',
                                'mercredi' => 'Mercredi',
                                'jeudi' => 'Jeudi',
                                'vendredi' => "Vendredi",
                                "samedi" => "Samedi",
                                'dimanche' => "Dimanche"
                            ])->native(false)->required()->label('Le jour'),
                        TimePicker::make('startAt')->required()->label('Heure d\'ouverture'),
                        TimePicker::make('endAt')->required()->label('Heure de fermeture')
                    ])->columns(3),
                    FileUpload::make('banniere')
                        ->disk('uploads_image')
                        ->acceptedFileTypes(['image/*'])->columnSpanFull(),
                ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('banniere')->disk('uploads_image'),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('phone')
                    ->searchable(),
                TextColumn::make('user.name')->label(__('Responsable'))
                    ->badge()
                    ->searchable(),
                ToggleColumn::make('is_active'),
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
            ->headerActions([
                CreateAction::make()
            ])
            ->emptyStateActions([
                CreateAction::make(),
            ]);
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            // ...
            EditRestaurant::class,
            ManageProducts::class

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
            'index' => ListRestaurants::route('/'),
            'create' => CreateRestaurant::route('/create'),
            'edit' => EditRestaurant::route('/{record}/edit'),
            'manage' => ManageProducts::route('/{record}/manage'),
        ];
    }

    public static function toSlug($string): String
    {
        return Str::slug($string);
    }
}
