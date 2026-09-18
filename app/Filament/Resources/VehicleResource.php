<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VehicleResource\Pages;
use App\Models\Vehicle;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class VehicleResource extends Resource
{
    protected static ?string $model = Vehicle::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationGroup = 'Location';

    protected static ?string $label = 'Véhicule';

    protected static ?string $pluralLabel = 'Véhicules';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Section::make('Le véhicule')
                ->description('Ce que le client voit dans le catalogue.')
                ->columns(2)
                ->schema([
                    TextInput::make('brand')
                        ->label('Marque')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('model')
                        ->label('Modèle')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('plate_number')
                        ->label('Immatriculation')
                        ->required()
                        ->maxLength(255)
                        // Deux vehicules ne partagent pas une plaque : sans
                        // cette regle, l'erreur remonte en violation SQL brute.
                        ->unique(ignoreRecord: true),

                    TextInput::make('seats')
                        ->label('Nombre de places')
                        ->numeric()
                        ->minValue(1)
                        ->maxValue(64)
                        ->default(4)
                        ->required(),

                    Textarea::make('description')
                        ->label('Description')
                        ->rows(3)
                        ->columnSpanFull(),

                    FileUpload::make('image')
                        ->label('Photo')
                        ->image()
                        ->imageEditor()
                        ->directory('vehicles')
                        ->columnSpanFull(),
                ]),

            Section::make('Tarif')
                ->description('Le prix est calculé par le serveur à partir de ce tarif : il ne vient jamais du client.')
                ->columns(2)
                ->schema([
                    TextInput::make('hourly_rate')
                        ->label('Tarif horaire')
                        ->numeric()
                        ->minValue(0.01)
                        ->step(0.01)
                        ->required(),

                    Select::make('currency_id')
                        ->label('Devise')
                        ->relationship('currency', 'title')
                        ->native(false)
                        ->preload()
                        ->required(),

                    Toggle::make('is_active')
                        ->label('Proposé à la location')
                        ->helperText("Décoché, le véhicule disparaît du catalogue sans toucher aux réservations déjà prises.")
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')->label('')->circular(),

                TextColumn::make('reference')
                    ->label('Référence')
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('brand')
                    ->label('Véhicule')
                    ->formatStateUsing(fn (Vehicle $record) => $record->name)
                    ->description(fn (Vehicle $record) => $record->plate_number)
                    ->searchable(['brand', 'model', 'plate_number'])
                    ->sortable(),

                TextColumn::make('seats')
                    ->label('Places')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('hourly_rate')
                    ->label('Tarif horaire')
                    ->formatStateUsing(fn ($state, Vehicle $record) => number_format((float) $state, 2, ',', ' ') . ' ' . $record->currency?->code)
                    ->sortable(),

                TextColumn::make('bookings_count')
                    ->label('Réservations')
                    ->counts('bookings')
                    ->alignCenter()
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('Au catalogue')
                    ->boolean(),
            ])
            ->defaultSort('brand')
            ->filters([
                TernaryFilter::make('is_active')->label('Au catalogue'),
                Tables\Filters\TrashedFilter::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\RestoreBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListVehicles::route('/'),
            'create' => Pages\CreateVehicle::route('/create'),
            'edit' => Pages\EditVehicle::route('/{record}/edit'),
        ];
    }
}
