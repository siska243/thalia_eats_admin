<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CategoryProductResource\Pages\CreateCategoryProduct;
use App\Filament\Resources\CategoryProductResource\Pages\EditCategoryProduct;
use App\Filament\Resources\CategoryProductResource\Pages\ListCategoryProducts;
use App\Filament\Resources\CategoryProductResource\Pages\ManageProducts;
use App\Models\CategoryProduct;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class CategoryProductResource extends Resource
{
    protected static ?int $navigationSort = 4;

    protected static ?string $model = CategoryProduct::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-table-cells';

    protected static ?string $modelLabel = 'Catégorie';

    protected static ?string $pluralModelLabel = 'Catégories';

    protected static ?string $navigationLabel = 'Catégories';

    protected static string|\UnitEnum|null $navigationGroup = 'Restauration';

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(65535)
                        ->columnSpanFull(),
                ]),
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
                EditAction::make(),
                DeleteAction::make(),
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
            'products' => ManageProducts::route('/{record}/products'),
        ];
    }
}
