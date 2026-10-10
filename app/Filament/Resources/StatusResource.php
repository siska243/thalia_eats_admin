<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ColorColumn;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\CreateAction;
use App\Filament\Resources\StatusResource\Pages\ListStatuses;
use App\Filament\Resources\StatusResource\Pages\CreateStatus;
use App\Filament\Resources\StatusResource\Pages\EditStatus;
use App\Filament\Resources\StatusResource\Pages;
use App\Filament\Resources\StatusResource\RelationManagers;
use App\Models\Status;
use Filament\Forms;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Guava\IconPicker\Forms\Components\IconPicker;
use Guava\IconPicker\Tables\Columns\IconColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class StatusResource extends Resource
{
    protected static ?string $model = Status::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-minus-circle';

    protected static ?string $label = "Status";
    protected static ?string $navigationLabel = "Status";
    protected static string | \UnitEnum | null $navigationGroup = "Parametre";
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                //
                Section::make()
                    ->columns(2)
                    ->schema([
                    TextInput::make('title'),
                    ColorPicker::make('color'),
                    ColorPicker::make('bg_color'),
                    IconPicker::make('icon')->preload(),
                ])

            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                //
                IconColumn::make('icon'),
                TextColumn::make('title'),
                ColorColumn::make('color'),
                ColorColumn::make('bg_color')
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
            'index' => ListStatuses::route('/'),
            'create' => CreateStatus::route('/create'),
            'edit' => EditStatus::route('/{record}/edit'),
        ];
    }
}
