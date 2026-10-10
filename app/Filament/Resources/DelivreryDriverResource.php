<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\CreateAction;
use App\Filament\Resources\DelivreryDriverResource\Pages\EditDelivreryDriver;
use App\Filament\Resources\DelivreryDriverResource\Pages\ManageCommandeProducts;
use App\Filament\Resources\DelivreryDriverResource\Pages\ListDelivreryDrivers;
use App\Filament\Resources\DelivreryDriverResource\Pages\CreateDelivreryDriver;
use App\Filament\Resources\DelivreryDriverResource\Pages;
use App\Filament\Resources\DelivreryDriverResource\RelationManagers;
use App\Models\DelivreryDriver;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\Page;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DelivreryDriverResource extends Resource
{
    protected static ?string $model = DelivreryDriver::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-user';
    protected static string | \UnitEnum | null $navigationGroup = "Livraison";
    protected static ?string $navigationLabel = "Livreur";

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(2)
                    ->schema(
                        [
                            Select::make('user_id')
                                ->relationship('user', 'name')
                                ->preload()
                                ->searchable(),
                            DatePicker::make('birth_date'),
                            TextInput::make('id_card')
                                ->required()
                                ->maxLength(255),
                            Textarea::make('contract')
                                ->columnSpanFull(),
                            Toggle::make('is_active')
                                ->required(),
                        ]
                    )

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.name')
                    ->sortable(),
                TextColumn::make('birth_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('id_card')->label('Numéro pièce d\'identité')
                    ->searchable(),
                ToggleColumn::make('is_active')
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

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([

            EditDelivreryDriver::class,
            ManageCommandeProducts::class
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDelivreryDrivers::route('/'),
            'create' => CreateDelivreryDriver::route('/create'),
            'edit' => EditDelivreryDriver::route('/{record}/edit'),
            'commande' => ManageCommandeProducts::route('/{record}/commandes'),
        ];
    }
}
