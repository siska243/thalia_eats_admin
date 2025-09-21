<?php

namespace App\Filament\Resources\RestaurantResource\Pages;

use App\Filament\Resources\CommandeResource;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\RestaurantResource;
use App\Models\Currency;
use App\Models\Product;
use App\Models\Restaurant;
use App\Models\SubCategoryProduct;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Actions\ActionGroup;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\CreateAction;
use Filament\Tables\Actions\DeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Actions\EditAction;
use Filament\Tables\Actions\ViewAction;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ManageProducts extends ManageRelatedRecords
{
    protected static string $resource = RestaurantResource::class;

    protected static string $relationship = 'product';

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    public static function getNavigationLabel(): string
    {
        return 'Products';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema(
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
                                        ->relationship('restaurant', 'name')
                                        ->options(Restaurant::query()->where('is_active', true)->pluck('name', 'id'))
                                        ->disabled()
                                        ->default(fn() => $this->record->id)
                                        ->searchable()
                                    ,
                                    Select::make('sub_category_product_id')
                                        ->required()
                                        ->relationship('sub_category_product', 'title')
                                        ->options(SubCategoryProduct::where('is_active', true)->pluck('title', 'id'))
                                        ->searchable()
                                    ,
                                    Select::make('currency_id')
                                        ->relationship('currency', 'title')->label('Devise')->required()
                                        ->options(Currency::where('is_active', 1)->pluck('title', 'id'))
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
                        ]
                    )
                ]

            );
    }

    public function table(Table $table): Table
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
            ->actions([

                Tables\Actions\Action::make("edit")->url(fn(Product $record): string => ProductResource::getUrl('edit', ['record' => $record]))->label(__("Edit")),
                ViewAction::make(),
                DeleteAction::make(),


            ])
            ->bulkActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                ->label(__("Create Product"))
                ->url(fn(): string => ProductResource::getUrl('create', ['record' => $this->record->id]))
                ->icon('heroicon-o-plus')
                ->openUrlInNewTab(),
            ])
            ->emptyStateActions([
                CreateAction::make(),
            ]);
    }
}
