<?php

namespace App\Filament\Resources\SubCategoryProductResource\Pages;

use App\Filament\Resources\SubCategoryProductResource;
use App\Models\Currency;
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
use Filament\Pages\SubNavigationPosition;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ManageProducts extends ManageRelatedRecords
{
    protected static string $resource = SubCategoryProductResource::class;

    protected static string $relationship = 'product';

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';


    protected static SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    public static function getNavigationLabel(): string
    {
        return 'Products';
    }

    public function form(Form $form): Form
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

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
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
                Tables\Filters\TrashedFilter::make()
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make(),

            ])
            ->actions([
                Tables\Actions\EditAction::make(),

            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([

                ]),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]));
    }
}
