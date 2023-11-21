<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DelivreryDriverResource\Pages;
use App\Filament\Resources\DelivreryDriverResource\RelationManagers;
use App\Models\DelivreryDriver;
use App\Models\User;
use Filament\Forms;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DelivreryDriverResource extends Resource
{
    protected static ?string $model = DelivreryDriver::class;

    protected static ?string $navigationIcon = 'heroicon-o-user';
    protected static ?string $navigationGroup = "Livraison";
    protected static ?string $navigationLabel = "Livreur";
    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('user_id')
                ->relationship('user','name')
                ->options(User::whereHas('roles', function ($query) {
                $query->where('name', 'drivers');
            })->pluck('name', 'id'))
                ->preload()
                ->searchable(),
                Forms\Components\DatePicker::make('birth_date'),
                Forms\Components\TextInput::make('id_card')
                    ->required()
                    ->maxLength(255),
                Forms\Components\Textarea::make('contract')
                    ->columnSpanFull(),
                Forms\Components\Toggle::make('is_active')
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('birth_date')
                    ->date()
                    ->sortable(),
                Tables\Columns\TextColumn::make('id_card')->label('Numéro pièce d\'identité')
                    ->searchable(),
                Tables\Columns\IconColumn::make('is_active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateActions([
                Tables\Actions\CreateAction::make(),
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
            'index' => Pages\ListDelivreryDrivers::route('/'),
            'create' => Pages\CreateDelivreryDriver::route('/create'),
            'edit' => Pages\EditDelivreryDriver::route('/{record}/edit'),
        ];
    }
}
