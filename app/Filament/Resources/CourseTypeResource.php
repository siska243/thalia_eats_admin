<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CourseTypeResource\Pages\CreateCourseType;
use App\Filament\Resources\CourseTypeResource\Pages\EditCourseType;
use App\Filament\Resources\CourseTypeResource\Pages\ListCourseTypes;
use App\Models\CourseType;
use App\Services\Rental\BookingPricing;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * Les types de course.
 *
 * « Le type c'est un referentiel » : il s'edite ici, et chaque ligne porte ses
 * propres regles. Ajouter un quatrieme type ne demande donc aucune livraison —
 * c'est tout l'interet, et c'est pourquoi aucune regle ne vit dans le code.
 */
class CourseTypeResource extends Resource
{
    protected static ?string $model = CourseType::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static ?string $modelLabel = 'Type de course';

    protected static ?string $pluralModelLabel = 'Types de course';

    protected static ?string $navigationLabel = 'Types de course';

    protected static ?string $label = 'type de course';

    protected static ?string $pluralLabel = 'types de course';

    protected static string|\UnitEnum|null $navigationGroup = 'Location';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Le type')
                ->columns(2)
                ->schema([
                    TextInput::make('code')
                        ->label('Code')
                        ->required()
                        ->maxLength(8)
                        // Deux types ne partagent pas un code : c'est par lui
                        // que les applications les reconnaissent.
                        ->unique(ignoreRecord: true),

                    TextInput::make('title')
                        ->label('Nom')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('description')
                        ->label('Description')
                        ->maxLength(255)
                        ->helperText('Montrée au client sous le nom du type.')
                        ->columnSpanFull(),

                    TextInput::make('position')
                        ->label('Ordre')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->required(),

                    Toggle::make('is_active')
                        ->label('Proposé aux clients')
                        ->default(true),
                ]),

            Section::make('Les règles de ce type')
                ->description('Elles vivent sur le type, pas dans le code : un nouveau type se règle entièrement ici.')
                ->columns(2)
                ->schema([
                    TextInput::make('minimum_hours')
                        ->label('Durée minimale')
                        ->suffix('heures')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Laissé vide, aucune durée minimale propre à ce type.'),

                    Select::make('forced_mode')
                        ->label('Mode imposé')
                        ->native(false)
                        ->options([
                            BookingPricing::MODE_HOURLY => "À l'heure",
                            BookingPricing::MODE_DAILY => 'À la journée',
                        ])
                        ->helperText('Laissé vide, le client choisit. Imposé, l’autre mode est refusé.'),

                    Toggle::make('note_required')
                        ->label('Commentaire obligatoire')
                        ->helperText('Le client doit préciser sa course avant de réserver.'),
                ]),

            Section::make('Parking')
                ->description("Pour les courses où le véhicule entre dans une enceinte payante — l'aéroport, typiquement. Le client choisit, et c'est facturé à l'heure.")
                ->columns(2)
                ->schema([
                    Toggle::make('parking_option')
                        ->label('Proposer l’option parking')
                        ->live(),

                    TextInput::make('parking_hourly_fee')
                        ->label('Tarif du parking')
                        ->prefix('$')
                        ->suffix('par heure')
                        ->numeric()
                        ->minValue(0)
                        ->required(fn (Get $get) => (bool) $get('parking_option'))
                        ->disabled(fn (Get $get) => ! $get('parking_option'))
                        ->dehydrated()
                        // Grise et non cache : Filament ne derive pas l'etat
                        // d'un champ cache, et l'ancien tarif resterait en base
                        // apres qu'on a retire l'option.
                        ->dehydrateStateUsing(fn ($state, Get $get) => $get('parking_option') ? $state : null),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->columns([
                TextColumn::make('code')->label('Code')->badge()->sortable(),
                TextColumn::make('title')->label('Nom')->searchable(),
                TextColumn::make('minimum_hours')
                    ->label('Minimum')
                    ->placeholder('—')
                    ->suffix(' h'),
                TextColumn::make('forced_mode')
                    ->label('Mode imposé')
                    ->placeholder('au choix')
                    ->formatStateUsing(fn (?string $state) => $state === BookingPricing::MODE_DAILY ? 'journée' : 'heure'),
                IconColumn::make('note_required')->label('Commentaire')->boolean(),
                TextColumn::make('parking_hourly_fee')
                    ->label('Parking')
                    ->placeholder('—')
                    ->money('USD'),
                ToggleColumn::make('is_active')->label('Actif'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->headerActions([CreateAction::make()])
            ->emptyStateActions([CreateAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCourseTypes::route('/'),
            'create' => CreateCourseType::route('/create'),
            'edit' => EditCourseType::route('/{record}/edit'),
        ];
    }
}
