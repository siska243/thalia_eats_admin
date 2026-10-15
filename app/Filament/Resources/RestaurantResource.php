<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RestaurantResource\Pages\CreateRestaurant;
use App\Filament\Resources\RestaurantResource\Pages\EditRestaurant;
use App\Filament\Resources\RestaurantResource\Pages\ListRestaurants;
use App\Filament\Resources\RestaurantResource\Pages\ManageProducts;
use App\Models\Restaurant;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class RestaurantResource extends Resource
{
    protected static ?string $model = Restaurant::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home-modern';

    protected static ?string $label = 'Nos restaurants';

    protected static string|\UnitEnum|null $navigationGroup = 'Thalia eats';

    protected static ?int $navigationSort = 2;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

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
                    /*
                     * La position du restaurant.
                     *
                     * C'est elle qui decide si un client peut commander ici :
                     * au-dela du rayon de sa ville, la commande est refusee.
                     * Facultative — sans elle on retombe sur le centre de la
                     * commune — mais une position precise evite de refuser un
                     * client qui est a portee, ou d'en accepter un qui ne l'est
                     * pas.
                     */
                    TextInput::make('location.lat')
                        ->label('Latitude')
                        ->helperText('Facultatif. Sans coordonnees, on utilise le centre de la commune.')
                        ->numeric()
                        ->minValue(-90)
                        ->maxValue(90),
                    TextInput::make('location.lng')
                        ->label('Longitude')
                        ->numeric()
                        ->minValue(-180)
                        ->maxValue(180),
                    RichEditor::make('description')->columnSpanFull(),
                ])->columns(2),
                \Filament\Schemas\Components\Section::make('Information supplementaire')->schema([
                    Repeater::make('openHours')->schema([
                        Select::make('day')
                            ->options([
                                'lundi' => 'Lundi',
                                'mardi' => 'Mardi',
                                'mercredi' => 'Mercredi',
                                'jeudi' => 'Jeudi',
                                'vendredi' => 'Vendredi',
                                'samedi' => 'Samedi',
                                'dimanche' => 'Dimanche',
                            ])->native(false)->required()->label('Le jour'),
                        TimePicker::make('startAt')->required()->label('Heure d\'ouverture'),
                        TimePicker::make('endAt')->required()->label('Heure de fermeture'),
                    ])->columns(3),
                    FileUpload::make('banniere')
                        ->disk('uploads_image')
                        ->acceptedFileTypes(['image/*'])->columnSpanFull(),
                ]),
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
                CreateAction::make(),
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
            ManageProducts::class,

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

    public static function toSlug($string): string
    {
        return Str::slug($string);
    }
}
