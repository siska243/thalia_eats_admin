<?php

namespace App\Filament\Resources\CategoryProductResource\Pages;

use App\Filament\Resources\CategoryProductResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Grid;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Actions\BulkActionGroup;
use Filament\Tables\Actions\CreateAction;
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
    protected static string $resource = CategoryProductResource::class;

    protected static string $relationship = 'sub_category_product';

    protected static ?string $navigationIcon = 'heroicon-o-ellipsis-horizontal-circle';

    public static function getNavigationLabel(): string
    {
        return 'Products';
    }

    public function form(Form $form): Form
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
                    ->disk('uploads_image')
                    ->acceptedFileTypes(['image/*'])->columnSpanFull(),

            ])
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
                ->columns([
                    ImageColumn::make('picture')->disk('uploads_image')->circular(),
                    TextColumn::make('title')
                        ->sortable()
                    ->searchable()
                    ,
                    TextColumn::make('category_product.title')
                        ->searchable()
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

            ;
    }
}
