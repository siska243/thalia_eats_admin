<?php

namespace App\Filament\Resources\CommandeResource\Pages;

use App\Filament\Resources\CommandeResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ManageCommandeProducts extends ManageRelatedRecords
{
    protected static string $resource = CommandeResource::class;

    protected static string $relationship = 'commande_products';

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    public static function getNavigationLabel(): string
    {
        return 'Commande Products';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\TextInput::make('title')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([

                Tables\Columns\ImageColumn::make('product.picture')
                    ->disk('uploads_image')->circular()
                    ,
                Tables\Columns\TextColumn::make('product.title')
                ->searchable()
                ,
                Tables\Columns\TextColumn::make('product.restaurant.name'),
                Tables\Columns\TextColumn::make('quantity'),
                Tables\Columns\TextColumn::make('price'),
                Tables\Columns\TextColumn::make('currency.code')->badge(),

                Tables\Columns\TextColumn::make('user.name'),
                Tables\Columns\TextColumn::make('created_at')
                ->dateTime()
                ,
            ])
            ->filters([
                //
            ])
            ->headerActions([

            ])
            ->actions([

            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([

                ]),
            ]);
    }
}
