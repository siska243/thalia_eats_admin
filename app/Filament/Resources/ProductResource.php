<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use App\Filament\Resources\ProductResource\Pages\ListProducts;
use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\SubCategoryProduct;
use Filament\Forms\Components\{FileUpload, Section, Select, Textarea, TextInput};
use Filament\Resources\Resource;
use Filament\Tables\Columns\{ImageColumn, TextColumn, ToggleColumn};
use Filament\Tables\Table;
use Filament\Tables\Actions\{ActionGroup,
    BulkActionGroup,
    DeleteBulkAction,
    EditAction,
    CreateAction,
    DeleteAction,
    ViewAction
};

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?string $navigationLabel = "Liste";
    protected static string | \UnitEnum | null $navigationGroup = "Produits";

    public static function form(Schema $schema): Schema
    {

        return $schema
            ->components(
                [
                    \Filament\Schemas\Components\Section::make()->schema(
                        [
                            \Filament\Schemas\Components\Section::make('Information basique')
                                ->schema([
                                    TextInput::make('title')
                                        ->required()
                                        ->maxLength(65535),
                                    Select::make('restaurant_id')
                                        ->required()
                                        ->default(fn() => request()->query('record'))
                                        ->relationship('restaurant', 'name')
                                        ->options(Restaurant::query()
                                            ->where('is_active', true)
                                            ->pluck('name', 'id'))
                                        ->searchable()
                                    ,
                                    Select::make('sub_category_product_id')
                                        ->required()
                                        ->relationship('sub_category_product', 'title')
                                        ->options(SubCategoryProduct::query()->where('is_active', true)->pluck('title', 'id'))
                                        ->searchable()
                                    ,
                                    Select::make('currency_id')
                                        ->relationship('currency', 'title')->label('Devise')->required()
                                        ->options(Currency::query()->where('is_active', 1)->pluck('title', 'id'))
                                ])->columns(2),

                            \Filament\Schemas\Components\Section::make('Information complementaire')
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
                                ])
                        ]
                    )
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
                TextColumn::make('restaurant.name')
                    ->sortable(),
                TextColumn::make('sub_category_product.title')
                    ->label('categorie')
                    ->sortable(),
                ToggleColumn::make('is_active')
                ,
                ToggleColumn::make('preview')
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
                \Filament\Actions\ActionGroup::make([
                    \Filament\Actions\EditAction::make(),
                    \Filament\Actions\ViewAction::make(),
                    \Filament\Actions\DeleteAction::make(),
                ])

            ])
            ->toolbarActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateActions([
                \Filament\Actions\CreateAction::make(),
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
