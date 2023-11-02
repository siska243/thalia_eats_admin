<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SubCategoryProductResource\Pages;
use App\Filament\Resources\SubCategoryProductResource\RelationManagers;
use App\Models\CategoryProduct;
use App\Models\SubCategoryProduct;
use Filament\Forms\Components\{Grid, TextInput, Textarea, Toggle, Select};
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables\Actions\{CreateAction, DeleteBulkAction,EditAction,ViewAction,DeleteAction, BulkActionGroup};
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Filament\Forms\Components\FileUpload;
use Filament\Tables\Columns\{ImageColumn, TextColumn, CheckboxColumn, ToggleColumn};
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class SubCategoryProductResource extends Resource
{
    protected static ?string $model = SubCategoryProduct::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Form $form): Form
    {

        return $form->schema([
            Grid::make(2)->schema([
                Select::make('category_product_id')->label('Categorie')
                    ->relationship('category_product', 'title')
                    ->required(),
                TextInput::make('title')
                    ->required(),
                Toggle::make('is_active')
                    ->required(),
                FileUpload::make('picture')
                ->preserveFilenames()
                ->disk('public')
                ->directory('uploads/image')

                ->visibility('public')
                ->getUploadedFileNameForStorageUsing(
                    fn (TemporaryUploadedFile $file): string => (string) str($file->getClientOriginalName())
                    ->prepend(now()->timestamp),
                )
                ->acceptedFileTypes(['image/*'])->
                columnSpanFull(),

            ])
        ])
            ;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
            TextColumn::make('title')
                ->sortable(),
                TextColumn::make('category_product.title')
                    ->sortable(),
                ToggleColumn::make('is_active')->label('Activé'),
                ImageColumn::make('picture'),
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
            ->actions([
                EditAction::make(),
                ViewAction::make()

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
            'index' => Pages\ListSubCategoryProducts::route('/'),
            'create' => Pages\CreateSubCategoryProduct::route('/create'),
            'edit' => Pages\EditSubCategoryProduct::route('/{record}/edit'),
        ];
    }
}
