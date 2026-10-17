<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaimentMethodResource\Pages\CreatePaimentMethod;
use App\Filament\Resources\PaimentMethodResource\Pages\EditPaimentMethod;
use App\Filament\Resources\PaimentMethodResource\Pages\ListPaimentMethods;
use App\Models\PaimentMethod;
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

class PaimentMethodResource extends Resource
{
    protected static ?int $navigationSort = 8;

    protected static ?string $model = PaimentMethod::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $modelLabel = 'Moyen de paiement';

    protected static ?string $pluralModelLabel = 'Moyens de paiement';

    protected static ?string $navigationLabel = 'Moyens de paiement';

    protected static string|\UnitEnum|null $navigationGroup = 'Paramètres';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()->schema([
                    TextInput::make('title')
                        ->required()
                        ->maxLength(255),
                ]),

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable(),
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
            'index' => ListPaimentMethods::route('/'),
            'create' => CreatePaimentMethod::route('/create'),
            'edit' => EditPaimentMethod::route('/{record}/edit'),
        ];
    }
}
