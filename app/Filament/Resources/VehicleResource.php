<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VehicleResource\Pages\CreateVehicle;
use App\Filament\Resources\VehicleResource\Pages\EditVehicle;
use App\Filament\Resources\VehicleResource\Pages\ListVehicles;
use App\Filament\Resources\VehicleResource\RelationManagers\CoursePricesRelationManager;
use App\Models\City;
use App\Models\Town;
use App\Models\Vehicle;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Collection;

class VehicleResource extends Resource
{
    protected static ?string $model = Vehicle::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static string|\UnitEnum|null $navigationGroup = 'Location';

    protected static ?string $label = 'Véhicule';

    protected static ?string $pluralLabel = 'Véhicules';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
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

                    /*
                     * La ville ou ce vehicule travaille.
                     *
                     * « Il y a pas des limites des kilometres mais il y'a des
                     * limites par rapport a la ville. » Laissee vide, le
                     * vehicule se reserve PARTOUT : c'est l'etat du parc
                     * existant, et la remplir est ce qui le rattache.
                     */
                    Select::make('city_id')
                        ->label('Ville de rattachement')
                        ->relationship('city', 'title')
                        ->searchable()
                        ->preload()
                        ->native(false)
                        ->helperText('Laissée vide, le véhicule accepte les courses de toutes les villes.')
                        ->live(),

                    /*
                     * Les communes d'ou ce vehicule sort.
                     *
                     * « Aucune commune selectionnee, on prend toutes les
                     * communes. » Vide ne ferme donc rien — meme doctrine que
                     * la ville laissee vide.
                     *
                     * Les communes proposees sont celles de la ville choisie :
                     * en cocher une d'ailleurs ne servirait a rien, la ville
                     * etant tranchee avant.
                     */
                    Select::make('towns')
                        ->label('Communes desservies')
                        ->relationship(
                            'towns',
                            'title',
                            fn (Builder $query, Get $get) => $get('city_id')
                                ? $query->where('city_id', $get('city_id'))->orderBy('title')
                                : $query->orderBy('title'),
                        )
                        ->multiple()
                        ->preload()
                        ->searchable()
                        ->native(false)
                        ->helperText('Aucune commune cochée : toutes celles de la ville sont acceptées.')
                        ->columnSpanFull(),

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

                    /*
                     * Le disque est obligatoire : sans lui, Filament ecrit sur
                     * le disque par defaut (`storage/app`), que le serveur web
                     * ne sert pas — alors que l'API annonce l'image sous
                     * `/images/…` via ImageUrl. Le fichier partait donc a un
                     * endroit, l'adresse pointait ailleurs, et aucune photo ne
                     * s'affichait. `uploads_image` a pour racine
                     * `public/images`, c'est le disque des photos de plats.
                     */
                    FileUpload::make('image')
                        ->label('Photo')
                        ->disk('uploads_image')
                        ->image()
                        ->imageEditor()
                        ->directory('vehicles')
                        ->columnSpanFull(),
                ]),

            Section::make('Tarif')
                ->description('Le prix est calculé par le serveur à partir de ce tarif : il ne vient jamais du client. Un tarif modifié ici ne touche pas les réservations déjà prises, qui gardent le prix convenu.')
                ->columns(2)
                ->schema([
                    /*
                     * LE TARIF EST LA DECISION.
                     *
                     * Le proprietaire, le 16 octobre : « si le prix est defini
                     * et superieur a zero, la location a la journee ou a
                     * l'heure s'active ». Pas de case a cocher en plus : elle
                     * serait un second etat a tenir coherent avec le prix, et
                     * un vehicule pourrait se retrouver coche sans tarif, ou
                     * l'inverse.
                     *
                     * Pour retirer un mode, on efface son tarif. C'est le meme
                     * geste, et il ne peut pas se contredire.
                     */
                    TextInput::make('hourly_rate')
                        ->label('Tarif horaire')
                        ->prefix('$')
                        ->suffix('par heure')
                        ->numeric()
                        ->minValue(0.01)
                        ->step(0.01)
                        ->helperText("Renseigné, le véhicule se loue à l'heure. Laissez vide pour ne pas le proposer."),

                    TextInput::make('daily_rate')
                        ->label('Tarif journalier')
                        ->prefix('$')
                        ->suffix('par jour')
                        ->numeric()
                        ->minValue(0.01)
                        ->step(0.01)
                        ->helperText('Renseigné, le véhicule se loue à la journée. Laissez vide pour ne pas le proposer.'),

                    /*
                     * Les deux modalites propres au vehicule : la duree
                     * minimale et l'acompte. Laissees vides, ce sont les
                     * reglages generaux qui s'appliquent — et le parc existant
                     * n'a rien a saisir.
                     */
                    TextInput::make('minimum_hours')
                        ->label('Durée minimale')
                        ->suffix('heures')
                        ->numeric()
                        ->minValue(1)
                        ->helperText('Laissé vide, seules les durées générales s’appliquent. 24 h impose une journée pleine.'),

                    TextInput::make('deposit_percentage')
                        ->label('Acompte à la réservation')
                        ->suffix('%')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->helperText('Laissé vide, c’est le pourcentage général des réglages. Le solde est réglé au chauffeur.'),

                    Select::make('currency_id')
                        ->label('Devise')
                        ->relationship('currency', 'title')
                        ->native(false)
                        ->preload()
                        ->required(),

                    Select::make('default_chauffeur_id')
                        ->label('Chauffeur attitré')
                        ->relationship('defaultChauffeur', 'full_name')
                        ->native(false)
                        ->searchable()
                        ->preload()
                        ->helperText("Le conducteur habituel. Il pré-remplit les réservations de ce véhicule, sans les figer : l'affectation se change au cas par cas."),

                    Toggle::make('is_active')
                        ->label('Proposé à la location')
                        ->helperText('Décoché, le véhicule disparaît du catalogue sans toucher aux réservations déjà prises.')
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('image')->label('')->disk('uploads_image')->circular(),

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

                TextColumn::make('defaultChauffeur.full_name')
                    ->label('Chauffeur attitré')
                    ->placeholder('aucun')
                    ->toggleable(),

                TextColumn::make('city.title')
                    ->label('Ville')
                    ->placeholder('Toutes')
                    ->badge()
                    ->sortable(),

                TextColumn::make('seats')
                    ->label('Places')
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('hourly_rate')
                    ->label('Tarif horaire')
                    ->formatStateUsing(fn ($state, Vehicle $record) => number_format((float) $state, 2, ',', ' ').' '.$record->currency?->code)
                    ->sortable(),

                TextColumn::make('daily_rate')
                    ->label('Tarif journalier')
                    ->placeholder('Non proposé')
                    ->formatStateUsing(fn ($state, Vehicle $record) => $state === null
                        ? null
                        : number_format((float) $state, 2, ',', ' ').' '.$record->currency?->code)
                    ->sortable()
                    ->toggleable(),

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
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    static::zoneDesservieEnLot(),
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Poser la zone desservie sur plusieurs vehicules a la fois.
     *
     * Demande du proprietaire le 17 octobre : « ajoute le bulk action pour
     * selectionner la commune ou la ville a desservir ». Ouvrir quinze
     * fiches pour cocher les memes trois communes est le genre de corvee qui
     * finit par ne pas etre faite — et un parc mal renseigne se reserve de
     * partout, ce que la portee geographique cherche justement a eviter.
     *
     * La VILLE est facultative : laissee vide, chaque vehicule garde la
     * sienne. L'ecraser par defaut deplacerait tout un parc d'un clic.
     *
     * TROIS FACONS DE POSER LES COMMUNES, parce qu'elles ne veulent pas dire
     * la meme chose : ajouter aux existantes, les remplacer, ou tout vider.
     * « Vider » n'est pas « fermer » : sans commune, le vehicule dessert
     * TOUTE sa ville — c'est la doctrine deja posee sur la fiche, et elle ne
     * change pas ici.
     *
     * GARDE-FOU : une commune n'est attachee qu'aux vehicules de SA ville.
     * Sans lui, selectionner un parc de deux villes et cocher Gombe
     * rattacherait un vehicule de Lubumbashi a une commune de Kinshasa — une
     * incoherence silencieuse, que seul un client hors zone revelerait.
     */
    protected static function zoneDesservieEnLot(): BulkAction
    {
        return BulkAction::make('zoneDesservie')
            ->label('Zone desservie')
            ->icon('heroicon-o-map-pin')
            ->schema([
                Select::make('city_id')
                    ->label('Ville de rattachement')
                    ->options(fn () => City::query()->orderBy('title')->pluck('title', 'id'))
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->helperText('Laissée vide, chaque véhicule garde sa ville actuelle.')
                    ->live(),

                Radio::make('mode')
                    ->label('Les communes cochées')
                    ->options([
                        'ajouter' => 'S’ajoutent aux communes déjà desservies',
                        'remplacer' => 'Remplacent les communes actuelles',
                        'vider' => 'Sont effacées : tout véhicule desservira toute sa ville',
                    ])
                    ->default('ajouter')
                    ->required()
                    ->live(),

                Select::make('towns')
                    ->label('Communes desservies')
                    ->options(fn (Get $get) => Town::query()
                        ->when($get('city_id'), fn (Builder $q, $ville) => $q->where('city_id', $ville))
                        ->orderBy('title')
                        ->pluck('title', 'id'))
                    ->multiple()
                    ->searchable()
                    ->preload()
                    ->native(false)
                    // Rien a cocher quand on vide : le champ n'aurait aucun
                    // effet, et le laisser laisserait croire le contraire.
                    ->visible(fn (Get $get): bool => $get('mode') !== 'vider')
                    ->required(fn (Get $get): bool => $get('mode') !== 'vider')
                    ->helperText('Seules les communes de la ville du véhicule lui seront rattachées.')
                    ->columnSpanFull(),
            ])
            ->action(function (Collection $records, array $data): void {
                $communes = Town::query()
                    ->whereKey($data['towns'] ?? [])
                    ->get(['id', 'city_id']);

                foreach ($records as $vehicule) {
                    if (! empty($data['city_id'])) {
                        $vehicule->city_id = $data['city_id'];
                        $vehicule->save();
                    }

                    if ($data['mode'] === 'vider') {
                        $vehicule->towns()->detach();

                        continue;
                    }

                    /*
                     * Un vehicule sans ville accepte toutes les villes : on
                     * ne filtre alors rien, puisqu'il n'y a rien contre quoi
                     * filtrer.
                     */
                    $retenues = $vehicule->city_id
                        ? $communes->where('city_id', $vehicule->city_id)
                        : $communes;

                    $data['mode'] === 'remplacer'
                        ? $vehicule->towns()->sync($retenues->pluck('id'))
                        : $vehicule->towns()->syncWithoutDetaching($retenues->pluck('id'));
                }

                Notification::make()
                    ->title('Zone desservie mise à jour')
                    ->body(trans_choice(
                        '{1} Un véhicule modifié.|[2,*] :count véhicules modifiés.',
                        $records->count(),
                        ['count' => $records->count()],
                    ))
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getRelations(): array
    {
        return [
            // Les tarifs par type de course se gerent sous la fiche, et non
            // dans le formulaire : une ligne s'ajoute, se corrige et se
            // supprime seule, sans toucher au reste du vehicule.
            CoursePricesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVehicles::route('/'),
            'create' => CreateVehicle::route('/create'),
            'edit' => EditVehicle::route('/{record}/edit'),
        ];
    }
}
