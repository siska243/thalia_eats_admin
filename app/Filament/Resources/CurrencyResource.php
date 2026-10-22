<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CurrencyResource\Pages;
use App\Filament\Resources\CurrencyResource\Pages\ListCurrencies;
use App\Models\Currency;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class CurrencyResource extends Resource
{
    protected static ?string $modelLabel = 'Devise';

    protected static ?string $pluralModelLabel = 'Devises';

    protected static ?string $navigationLabel = 'Devises';

    protected static ?int $navigationSort = 5;

    protected static ?string $model = Currency::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $label = 'Devises';

    protected static string|\UnitEnum|null $navigationGroup = 'Paramètres';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema([

                        TextInput::make('title')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('code')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('icon')
                            ->maxLength(255),

                        TextInput::make('montant_minimum_paiement')
                            ->label('Montant minimum de paiement')
                            ->numeric()
                            ->minValue(0)
                            ->step(0.01)
                            ->default(0)
                            ->required()
                            ->helperText("En dessous, le paiement est refusé — carte comme mobile money. Le seuil est exprimé dans CETTE devise : la plateforme ne convertit rien. Laisser à 0 pour n'imposer aucun minimum. En dollars, la passerelle refuse de toute façon sous 2."),

                    ]),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable(),
                TextColumn::make('code')
                    ->searchable(),
                TextColumn::make('icon')
                    ->searchable(),

                TextColumn::make('montant_minimum_paiement')
                    ->label('Minimum de paiement')
                    ->numeric(decimalPlaces: 2)
                    ->sortable(),

                ToggleColumn::make('is_active'),
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

    public static function getPages(): array
    {
        return [
            'index' => ListCurrencies::route('/'),
            // 'create' => Pages\CreateCurrency::route('/create'),
            // 'edit' => Pages\EditCurrency::route('/{record}/edit'),
        ];
    }
}
