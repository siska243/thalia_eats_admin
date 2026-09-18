<?php

namespace App\Filament\Resources;

use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Actions\EditAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use App\Filament\Resources\ChauffeurResource\Pages\ListChauffeurs;
use App\Filament\Resources\ChauffeurResource\Pages\CreateChauffeur;
use App\Filament\Resources\ChauffeurResource\Pages\EditChauffeur;
use App\Filament\Resources\ChauffeurResource\Pages;
use App\Models\Chauffeur;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ChauffeurResource extends Resource
{
    protected static ?string $model = Chauffeur::class;

    protected static string | \BackedEnum | null $navigationIcon = 'heroicon-o-identification';

    protected static string | \UnitEnum | null $navigationGroup = 'Location';

    protected static ?string $label = 'Chauffeur';

    protected static ?string $pluralLabel = 'Chauffeurs';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identité')
                ->columns(2)
                ->schema([
                    Select::make('user_id')
                        ->label('Compte')
                        ->relationship('user', 'name')
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->helperText('Le compte avec lequel le chauffeur consulte ses courses.'),

                    TextInput::make('full_name')
                        ->label('Nom complet')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('phone')
                        ->label('Téléphone')
                        ->tel()
                        ->required()
                        ->maxLength(255),

                    FileUpload::make('photo')
                        ->label('Photo')
                        ->image()
                        ->imageEditor()
                        ->avatar()
                        ->directory('chauffeurs')
                        ->helperText('Montrée au client avant la course, pour reconnaître qui vient le chercher.'),
                ]),

            Section::make('Permis')
                ->description("Un permis périmé retire automatiquement le chauffeur des affectations possibles.")
                ->columns(2)
                ->schema([
                    TextInput::make('licence_number')
                        ->label('Numéro de permis')
                        ->maxLength(255),

                    DatePicker::make('licence_expires_at')
                        ->label("Expire le")
                        ->native(false)
                        ->displayFormat('d/m/Y'),

                    Toggle::make('is_active')
                        ->label('En service')
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('photo')->label('')->circular(),

                TextColumn::make('full_name')
                    ->label('Chauffeur')
                    ->description(fn (Chauffeur $record) => $record->phone)
                    ->searchable(['full_name', 'phone'])
                    ->sortable(),

                TextColumn::make('licence_expires_at')
                    ->label('Permis')
                    ->date('d/m/Y')
                    ->placeholder('non renseigné')
                    /*
                     * La couleur porte l'information : une liste de dates ne
                     * se lit pas, un permis perime en rouge se voit.
                     */
                    ->color(fn (Chauffeur $record) => $record->hasValidLicence() ? 'success' : 'danger')
                    ->description(fn (Chauffeur $record) => $record->hasValidLicence() ? null : 'périmé')
                    ->sortable(),

                TextColumn::make('bookings_count')
                    ->label('Courses')
                    ->counts('bookings')
                    ->alignCenter()
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label('En service')
                    ->boolean(),
            ])
            ->defaultSort('full_name')
            ->filters([
                TernaryFilter::make('is_active')->label('En service'),

                Filter::make('licence_expired')
                    ->label('Permis périmé')
                    ->query(fn (Builder $query) => $query->whereDate('licence_expires_at', '<', now())),

                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
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
            'index' => ListChauffeurs::route('/'),
            'create' => CreateChauffeur::route('/create'),
            'edit' => EditChauffeur::route('/{record}/edit'),
        ];
    }
}
