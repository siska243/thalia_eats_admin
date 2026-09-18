<?php

namespace App\Filament\Resources\CommandeResource\Pages;

use Filament\Schemas\Schema;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Actions\BulkActionGroup;
use App\Filament\Resources\CommandeResource;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ManageRelatedRecords;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ManageCommandeProducts extends ManageRelatedRecords
{
    protected static string $resource = CommandeResource::class;

    protected static string $relationship = 'commande_products';

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-shopping-cart';

    public static function getNavigationLabel(): string
    {
        return 'Commande Products';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->required()
                    ->maxLength(255),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([

                ImageColumn::make('product.picture')
                    ->disk('uploads_image')->circular()
                    ,
                TextColumn::make('product.title')
                ->searchable()
                ,
                TextColumn::make('product.restaurant.name'),
                TextColumn::make('quantity'),
                TextColumn::make('price'),
                TextColumn::make('currency.code')->badge(),

                TextColumn::make('user.name'),
                TextColumn::make('created_at')
                ->dateTime()
                ,
            ])
            ->filters([
                //
            ])
            ->headerActions([

            ])
            ->recordActions([

            ])
            ->toolbarActions([
                BulkActionGroup::make([

                ]),
            ]);
    }
}
