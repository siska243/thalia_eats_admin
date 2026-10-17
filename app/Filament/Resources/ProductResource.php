<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\SubCategoryProduct;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class ProductResource extends Resource
{
    protected static ?int $navigationSort = 4;

    protected static ?string $model = Product::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?string $navigationLabel = 'Liste';

    protected static string|\UnitEnum|null $navigationGroup = 'Restauration';

    public static function form(Schema $schema): Schema
    {

        return $schema
            ->components(
                [
                    Section::make()->schema(
                        [
                            Section::make('Information basique')
                                ->schema([
                                    TextInput::make('title')
                                        ->required()
                                        ->maxLength(65535),
                                    Select::make('restaurant_id')
                                        ->required()
                                        ->default(fn () => request()->query('record'))
                                        ->relationship('restaurant', 'name')
                                        ->options(Restaurant::query()
                                            ->where('is_active', true)
                                            ->pluck('name', 'id'))
                                        ->searchable(),
                                    Select::make('sub_category_product_id')
                                        ->required()
                                        ->relationship('sub_category_product', 'title')
                                        ->options(SubCategoryProduct::query()->where('is_active', true)->pluck('title', 'id'))
                                        ->searchable(),
                                    Select::make('currency_id')
                                        ->relationship('currency', 'title')->label('Devise')->required()
                                        ->options(Currency::query()->where('is_active', 1)->pluck('title', 'id')),
                                ])->columns(2),

                            Section::make('Information complementaire')
                                ->schema([TextInput::make('price')
                                    ->required()
                                    ->numeric()
                                    ->prefix('$'),
                                    TextInput::make('promotionnalPrice')
                                        ->numeric(),
                                    Textarea::make('description')
                                        ->columnSpanFull(),
                                    FileUpload::make('picture')
                                        ->disk('uploads_image')
                                        ->acceptedFileTypes(['image/*'])->columnSpanFull(),
                                ]),

                            /*
                             * Le stock, plat par plat.
                             *
                             * Le proprietaire, le 16 octobre : « le stock est
                             * pris en charge que si on l'active depuis l'admin
                             * dans chaque produit ».
                             *
                             * L'interrupteur n'est PAS une colonne : il lit et
                             * ecrit `stock_quantity`, dont `null` dit deja
                             * « non suivi ». Un booleen en base serait un
                             * second etat a tenir coherent avec le premier.
                             */
                            Section::make('Stock')
                                ->description('Sans suivi, le plat se commande sans limite de quantite, comme aujourd\'hui.')
                                ->schema([
                                    Toggle::make('suit_le_stock')
                                        ->label('Suivre le stock de ce plat')
                                        ->dehydrated(false)
                                        ->live()
                                        ->formatStateUsing(fn (?Product $record) => $record?->suitLeStock() ?? false),
                                    /*
                                     * Grise, et non cache, quand le suivi est
                                     * eteint. Filament ne derive PAS l'etat
                                     * d'un champ cache : la valeur d'avant
                                     * restait en base, et le plat restait
                                     * suivi apres qu'on a dit de ne plus le
                                     * suivre. Grise, le champ reste dans le
                                     * formulaire, part a l'enregistrement, et
                                     * l'admin voit qu'il existe.
                                     *
                                     * `$state` et non un nom choisi : Filament
                                     * injecte ses closures par le NOM du
                                     * parametre, et tout autre nom recevrait
                                     * null.
                                     */
                                    TextInput::make('stock_quantity')
                                        ->label('Quantite en stock')
                                        ->helperText('Diminue a chaque commande payee. Zero : le plat s\'affiche epuise.')
                                        ->numeric()
                                        ->minValue(0)
                                        ->required(fn (Get $get) => (bool) $get('suit_le_stock'))
                                        ->disabled(fn (Get $get) => ! $get('suit_le_stock'))
                                        ->dehydrated()
                                        ->dehydrateStateUsing(
                                            fn ($state, Get $get) => $get('suit_le_stock') ? $state : null
                                        ),
                                ])->columns(2),
                        ]
                    ),
                ]

            );
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('picture')->disk('uploads_image')->circular(),
                TextColumn::make('title')->searchable(),
                TextColumn::make('price')
                    ->money()
                    ->sortable(),
                /*
                 * « Cote admin on doit voir la quantite en stock. »
                 *
                 * Un tiret pour un plat non suivi : zero ferait croire a un
                 * plat epuise.
                 */
                TextColumn::make('stock_quantity')
                    ->label('Stock')
                    ->placeholder('-')
                    ->badge()
                    ->color(fn (?int $state) => $state === null ? 'gray' : ($state > 0 ? 'success' : 'danger'))
                    ->sortable(),
                TextColumn::make('restaurant.name')
                    ->sortable(),
                TextColumn::make('sub_category_product.title')
                    ->label('categorie')
                    ->sortable(),
                ToggleColumn::make('is_active'),
                ToggleColumn::make('preview'),

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
                ActionGroup::make([
                    EditAction::make(),
                    ViewAction::make(),
                    DeleteAction::make(),
                ]),

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
            'index' => ListProducts::route('/'),
            'create' => CreateProduct::route('/create'),
            'edit' => EditProduct::route('/{record}/edit'),
        ];
    }
}
