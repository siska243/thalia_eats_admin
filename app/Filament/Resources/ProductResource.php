<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Filament\Resources\ProductResource\RelationManagers;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\SubCategoryProduct;
use Filament\Forms\Components\{FileUpload, Section, Select, Textarea, TextInput};
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Columns\{ImageColumn, TextColumn, ToggleColumn};
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Filament\Tables\Actions\{ActionGroup, BulkActionGroup, DeleteBulkAction, EditAction, CreateAction, DeleteAction, ViewAction};

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-storefront';
    protected static ?string $navigationLabel = "Liste";
    protected static ?string $navigationGroup = "Produits";
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Information basique')
                 ->schema([
                    TextInput::make('title')
                    ->required()
                    ->maxLength(65535),
                Select::make('restaurant_id')
                ->required()
                ->relationship('restaurant','name')
                ->options(Restaurant::where('is_active', true)->pluck('name', 'id'))
                ->searchable()
                ,
                Select::make('sub_category_product_id')
                ->required()
                ->relationship('sub_category_product','title')
                ->options(SubCategoryProduct::where('is_active',true)->pluck('title','id'))
                ->searchable()
                ,
                Select::make('currency_id')
                ->relationship('currency','title')->label('Devise')->required()
                ->options(Currency::where('is_active',1)->pluck('title','id'))
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
                ])
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
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
                ImageColumn::make('picture')->disk('uploads_image'),
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
            ->actions([
                ActionGroup::make([
                    EditAction::make(),
                ViewAction::make(),
                DeleteAction::make(),
                ])

            ])
            ->bulkActions([
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
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
