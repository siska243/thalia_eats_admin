<?php

namespace App\Filament\Resources;

use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Schemas\Schema;
use App\Filament\Resources\SubCategoryProductResource\Pages\EditSubCategoryProduct;
use App\Filament\Resources\SubCategoryProductResource\Pages\ManageProducts;
use App\Filament\Resources\SubCategoryProductResource\Pages\ListSubCategoryProducts;
use App\Filament\Resources\SubCategoryProductResource\Pages\CreateSubCategoryProduct;
use App\Filament\Resources\SubCategoryProductResource\Pages;
use App\Filament\Resources\SubCategoryProductResource\RelationManagers;
use App\Models\CategoryProduct;
use App\Models\SubCategoryProduct;
use Filament\Pages\Page;
use Filament\Forms\Components\{Grid, Section, TextInput, Textarea, Toggle, Select};
use Filament\Resources\Resource;
use Filament\Tables\Actions\{CreateAction, DeleteBulkAction, EditAction, ViewAction, DeleteAction, BulkActionGroup};
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\FileUpload;
use Filament\Tables\Columns\{ImageColumn, TextColumn, CheckboxColumn, ToggleColumn};
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class SubCategoryProductResource extends Resource
{
    protected static ?string $model = SubCategoryProduct::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-ellipsis-horizontal-circle';
    protected static ?string $navigationLabel = "Sous Categorie";
    protected static string | \UnitEnum | null $navigationGroup = "Produits";

    protected static ?\Filament\Pages\Enums\SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function form(Schema $schema): Schema
    {

        return $schema->components([
            \Filament\Schemas\Components\Section::make()
                ->columns(2)
                ->schema([
                Select::make('category_product_id')->label('Categorie')
                    ->relationship('category_product', 'title')
                    ->required(),
                TextInput::make('title')
                    ->required(),
                Toggle::make('is_active')
                    ->required(),
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
                ImageColumn::make('picture')->disk('uploads_image')->circular(),
                TextColumn::make('title')
                    ->sortable(),
                TextColumn::make('category_product.title')
                    ->sortable(),
                ToggleColumn::make('is_active')->label('Activé'),

                TextColumn::make('created_at')->label('Date de création')
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
                \Filament\Actions\EditAction::make(),
                \Filament\Actions\ViewAction::make()

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

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            // ...
            EditSubCategoryProduct::class,
            ManageProducts::class,

        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSubCategoryProducts::route('/'),
            'create' => CreateSubCategoryProduct::route('/create'),
            'edit' => EditSubCategoryProduct::route('/{record}/edit'),
            'products'=>ManageProducts::route('/{record}/products'),
        ];
    }
}
