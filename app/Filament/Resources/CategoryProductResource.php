<?php

namespace App\Filament\Resources;

use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\TextInput;
use App\Filament\Resources\CategoryProductResource\Pages\EditCategoryProduct;
use App\Filament\Resources\CategoryProductResource\Pages\ManageProducts;
use App\Filament\Resources\CategoryProductResource\Pages\ListCategoryProducts;
use App\Filament\Resources\CategoryProductResource\Pages\CreateCategoryProduct;
use App\Filament\Resources\CategoryProductResource\Pages;
use App\Filament\Resources\CategoryProductResource\RelationManagers;
use App\Models\CategoryProduct;
use Filament\Forms;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Tables\Actions\{BulkActionGroup, CreateAction, DeleteAction, DeleteBulkAction, EditAction};
use Filament\Tables\Columns\{TextColumn, ToggleColumn};
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;


class CategoryProductResource extends Resource
{
    protected static ?string $model = CategoryProduct::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $navigationLabel = "Categorie";
    protected static string | \UnitEnum | null $navigationGroup = "Produits";


    protected static ?\Filament\Pages\Enums\SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(65535)
                        ->columnSpanFull()
                ])
            ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable(),
                ToggleColumn::make('is_active')->label('Activé'),
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
               \Filament\Actions\EditAction::make(),
                \Filament\Actions\DeleteAction::make()
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
            EditCategoryProduct::class,
            ManageProducts::class,

        ]);
    }
    public static function getPages(): array
    {
        return [
            'index' => ListCategoryProducts::route('/'),
            'create' => CreateCategoryProduct::route('/create'),
            'edit' => EditCategoryProduct::route('/{record}/edit'),
            'products'=>ManageProducts::route('/{record}/products'),
        ];
    }
}
