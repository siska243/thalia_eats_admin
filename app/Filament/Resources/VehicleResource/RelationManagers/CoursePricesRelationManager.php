<?php

namespace App\Filament\Resources\VehicleResource\RelationManagers;

use App\Models\CourseType;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Les tarifs d'un vehicule, type de course par type de course.
 *
 * Le proprietaire, le 16 octobre : « tu mets le prix par defaut comme il est
 * maintenant et ensuite tu ajoutes le prix en fonction du type (par heure,
 * jour) », puis « utilise relationManager ».
 *
 * Une ligne SEULEMENT pour les types dont le prix differe : sans ligne, le
 * vehicule garde le tarif de la section « Tarif » de sa fiche. Le parc
 * existant n'a donc rien a saisir, et on ne renseigne que l'exception.
 *
 * Les deux montants sont facultatifs separement : un type peut n'avoir qu'un
 * tarif journalier — une course hors agglomeration ne se vend pas a l'heure —
 * et l'autre colonne retombe alors sur le tarif habituel.
 */
class CoursePricesRelationManager extends RelationManager
{
    protected static string $relationship = 'coursePrices';

    protected static ?string $title = 'Tarifs par type de course';

    protected static ?string $modelLabel = 'tarif';

    protected static ?string $pluralModelLabel = 'tarifs';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('course_type_id')
                ->label('Type de course')
                ->options(fn () => CourseType::query()->actifs()->pluck('title', 'id'))
                ->native(false)
                ->required()
                // Un type ne peut avoir qu'un tarif par vehicule : la base le
                // garantit deja, autant le dire avant l'erreur SQL.
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn ($rule) => $rule->where('vehicle_id', $this->getOwnerRecord()->getKey()),
                ),

            TextInput::make('hourly_rate')
                ->label("Tarif de l'heure")
                ->prefix('$')
                ->numeric()
                ->minValue(0)
                ->helperText('Laissé vide, le tarif horaire habituel du véhicule s’applique.'),

            TextInput::make('daily_rate')
                ->label('Tarif de la journée')
                ->prefix('$')
                ->numeric()
                ->minValue(0)
                ->helperText('Laissé vide, le tarif journalier habituel s’applique.'),
        ])->columns(3);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('course_type_id')
            ->columns([
                TextColumn::make('courseType.title')
                    ->label('Type de course')
                    ->badge(),

                TextColumn::make('hourly_rate')
                    ->label("À l'heure")
                    // Un tiret, pas « 0 $ » : aucun tarif saisi ne veut pas
                    // dire gratuit, mais « le tarif habituel s'applique ».
                    ->placeholder('tarif habituel')
                    ->money(fn ($record) => $record->vehicle?->currency?->code ?? 'USD'),

                TextColumn::make('daily_rate')
                    ->label('À la journée')
                    ->placeholder('tarif habituel')
                    ->money(fn ($record) => $record->vehicle?->currency?->code ?? 'USD'),
            ])
            ->headerActions([
                CreateAction::make()->label('Ajouter un tarif'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading('Aucun tarif particulier')
            ->emptyStateDescription('Ce véhicule applique son tarif habituel à tous les types de course.');
    }
}
