<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProspectResource\Pages\EditProspect;
use App\Filament\Resources\ProspectResource\Pages\ListProspects;
use App\Models\Prospect;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Les demandes de partenariat venues du site.
 *
 * Rien ne se cree ici : les demandes arrivent du formulaire public, et
 * l'administration les traite. D'ou l'absence de page de creation, et des
 * coordonnees en lecture seule — les corriger a la main ferait diverger la
 * fiche de ce que le demandeur a reellement ecrit.
 *
 * Ce qui s'edite, c'est l'avancement : le statut et les notes, qui existent
 * pour qu'on ne rappelle pas deux fois le meme restaurant.
 */
class ProspectResource extends Resource
{
    protected static ?string $modelLabel = 'Demande de partenariat';

    protected static ?string $pluralModelLabel = 'Demandes de partenariat';

    protected static ?string $navigationLabel = 'Demandes de partenariat';

    protected static ?int $navigationSort = 2;

    protected static ?string $model = Prospect::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-hand-raised';

    protected static string|\UnitEnum|null $navigationGroup = 'Clients et partenaires';

    protected static ?string $label = 'Demande de partenariat';

    protected static ?string $pluralLabel = 'Demandes de partenariat';

    /**
     * Le nombre de demandes jamais traitees, en pastille.
     *
     * C'est la raison d'etre de l'ecran : une demande qui dort est un
     * restaurant qui va voir ailleurs. La pastille se voit depuis n'importe
     * quelle page de l'administration.
     */
    public static function getNavigationBadge(): ?string
    {
        $nouvelles = Prospect::query()->aTraiter()->count();

        return $nouvelles > 0 ? (string) $nouvelles : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('La demande')
                ->description('Telle que le visiteur l\'a remplie. Ces champs ne se modifient pas.')
                ->columns(2)
                ->schema([
                    TextInput::make('nom')->label('Nom')->disabled(),
                    TextInput::make('telephone')->label('Téléphone')->disabled(),
                    TextInput::make('email')->label('E-mail')->disabled(),
                    TextInput::make('commune')->label('Commune')->disabled(),

                    KeyValue::make('details')
                        ->label('Informations du métier')
                        ->disabled()
                        ->columnSpanFull(),

                    Textarea::make('message')
                        ->label('Son message')
                        ->disabled()
                        ->columnSpanFull(),
                ]),

            Section::make('Prospection')
                ->description('Où en est le contact. La date de premier appel se pose toute seule au premier changement de statut.')
                ->columns(2)
                ->schema([
                    Select::make('statut')
                        ->label('Avancement')
                        ->options(Prospect::STATUTS)
                        ->native(false)
                        ->required(),

                    Textarea::make('notes')
                        ->label('Notes')
                        ->rows(4)
                        ->columnSpanFull()
                        ->helperText('Ce qui a été dit, ce qui reste à faire.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label('Reçue le')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Métier')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state === Prospect::TYPE_RESTAURANT ? 'Restaurant' : 'Livreur')
                    ->color(fn (string $state) => $state === Prospect::TYPE_RESTAURANT ? 'info' : 'gray'),

                TextColumn::make('nom')
                    ->label('Demandeur')
                    // Le telephone sous le nom : c'est par la que le rappel se
                    // fait, et le chercher dans la fiche fait perdre un clic a
                    // chaque appel.
                    ->description(fn (Prospect $record) => $record->telephone)
                    ->searchable(['nom', 'telephone', 'email'])
                    ->sortable(),

                TextColumn::make('commune')
                    ->label('Commune')
                    ->placeholder('non précisée')
                    ->toggleable(),

                TextColumn::make('statut')
                    ->label('Avancement')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Prospect::STATUTS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        Prospect::STATUT_NOUVELLE => 'warning',
                        Prospect::STATUT_ACCEPTEE => 'success',
                        Prospect::STATUT_REFUSEE => 'danger',
                        default => 'info',
                    })
                    ->sortable(),

                TextColumn::make('contacte_at')
                    ->label('Premier contact')
                    ->dateTime('d/m/Y')
                    ->placeholder('jamais')
                    ->toggleable(),
            ])
            // La plus recente en premier : une demande d'il y a deux heures
            // se rappelle avant une d'il y a trois semaines.
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('type')
                    ->label('Métier')
                    ->options([
                        Prospect::TYPE_RESTAURANT => 'Restaurant',
                        Prospect::TYPE_LIVREUR => 'Livreur',
                    ]),

                SelectFilter::make('statut')
                    ->label('Avancement')
                    ->options(Prospect::STATUTS),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProspects::route('/'),
            'edit' => EditProspect::route('/{record}/edit'),
        ];
    }
}
