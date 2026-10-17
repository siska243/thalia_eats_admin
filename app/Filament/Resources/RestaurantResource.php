<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RestaurantResource\Pages\CreateRestaurant;
use App\Filament\Resources\RestaurantResource\Pages\EditRestaurant;
use App\Filament\Resources\RestaurantResource\Pages\ListRestaurants;
use App\Filament\Resources\RestaurantResource\Pages\ManageProducts;
use App\Models\Restaurant;
use App\Models\Town;
use App\Services\Geocodage\GeocodageIndisponible;
use App\Services\Geocodage\Nominatim;
use Filament\Actions\Action as FormAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class RestaurantResource extends Resource
{
    protected static ?string $navigationLabel = 'Restaurants';

    protected static ?string $model = Restaurant::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-home-modern';

    protected static ?string $label = 'Nos restaurants';

    protected static string|\UnitEnum|null $navigationGroup = 'Restauration';

    protected static ?int $navigationSort = 2;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    /**
     * La fiche d'un restaurant.
     *
     * Demande du proprietaire le 17 octobre : « ca doit etre un peu plus
     * epure ». Onze champs tenaient dans une seule section « Information de
     * base » : le nom, le responsable, l'adresse, la commune, la reference,
     * trois moyens de contact, deux coordonnees GPS et la description, tous
     * au meme niveau et tous ouverts d'emblee.
     *
     * EPURER, c'est montrer moins a la fois, pas supprimer. Les champs sont
     * groupes par question — qui, ou, comment le joindre — et ce qu'on ne
     * touche presque jamais se replie : la position precise, les horaires,
     * la banniere. Rien n'est retire.
     *
     * CE QUI MANQUAIT : `is_active` n'etait nulle part dans le formulaire.
     * On modifiait un restaurant sans jamais voir s'il etait en ligne, et il
     * fallait retourner a la liste pour le savoir.
     */
    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Le restaurant')
                    ->icon('heroicon-o-building-storefront')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nom du restaurant')
                            ->required()
                            ->maxLength(255),

                        Select::make('user_id')
                            ->label('Responsable')
                            ->relationship('user', 'name')
                            ->getOptionLabelFromRecordUsing(
                                fn ($record): string => trim("{$record->name} {$record->last_name}"),
                            )
                            ->searchable(['name', 'last_name', 'email'])
                            ->preload()
                            ->native(false)
                            ->required()
                            ->helperText('C’est lui qui verra les commandes dans l’application.'),

                        TextInput::make('reference')
                            ->label('Référence')
                            ->required()
                            ->maxLength(255),

                        Toggle::make('is_active')
                            ->label('Au catalogue')
                            ->helperText('Éteint, il disparaît des applications. Les commandes en cours ne sont pas touchées.'),

                        RichEditor::make('description')
                            ->label('Présentation')
                            ->columnSpanFull(),
                    ]),

                Section::make('Le joindre')
                    ->icon('heroicon-o-phone')
                    ->columns(3)
                    ->schema([
                        TextInput::make('phone')
                            ->label('Téléphone')
                            ->tel()
                            ->required()
                            ->maxLength(255),

                        TextInput::make('whatsapp')
                            ->label('WhatsApp')
                            ->tel()
                            ->maxLength(255),

                        TextInput::make('email')
                            ->label('Adresse électronique')
                            ->email()
                            ->maxLength(50),
                    ]),

                Section::make('Où il se trouve')
                    ->icon('heroicon-o-map-pin')
                    ->columns(2)
                    ->schema([
                        TextInput::make('adresse')
                            ->label('Adresse')
                            ->required()
                            ->maxLength(65535)
                            ->columnSpanFull(),

                        Select::make('town_id')
                            ->label('Commune')
                            ->relationship('town', 'title')
                            ->searchable()
                            ->preload()
                            ->native(false),

                        /*
                         * La position precise, repliee : elle est facultative
                         * et on ne la saisit qu'une fois.
                         *
                         * C'est pourtant elle qui decide si un client peut
                         * commander ici : au-dela du rayon de sa ville, la
                         * commande est refusee. Sans elle on retombe sur le
                         * centre de la commune — ce qui marche, mais refuse
                         * parfois un client a portee, ou en accepte un qui ne
                         * l'est pas.
                         */
                        Fieldset::make('Position précise')
                            ->columns(2)
                            ->columnSpanFull()
                            ->schema([
                                /*
                                 * RETROUVER LA POSITION DEPUIS L'ADRESSE.
                                 *
                                 * Demande du proprietaire : « on peut
                                 * utiliser OSM pour recuperer la position a
                                 * partir de l'adresse ». Saisir deux
                                 * coordonnees a la main suppose d'aller les
                                 * chercher ailleurs, et une faute de frappe
                                 * sur une decimale deplace le restaurant de
                                 * plusieurs kilometres — donc refuse des
                                 * clients a portee.
                                 *
                                 * Le bouton PROPOSE, il n'impose pas : la
                                 * position trouvee est posee dans les deux
                                 * champs, qui restent modifiables. Et il ne
                                 * part qu'au clic — jamais en boucle, la
                                 * politique de Nominatim l'interdit.
                                 */
                                Actions::make([
                                    FormAction::make('localiser')
                                        ->label('Trouver la position depuis l’adresse')
                                        ->icon('heroicon-m-map-pin')
                                        ->color('gray')
                                        ->action(function (Get $get, Set $set): void {
                                            $adresse = (string) $get('adresse');

                                            if (trim($adresse) === '') {
                                                Notification::make()
                                                    ->title('Renseignez d’abord l’adresse')
                                                    ->warning()
                                                    ->send();

                                                return;
                                            }

                                            /*
                                             * La commune ET sa ville precisent
                                             * la recherche : « Avenue du
                                             * Commerce » existe dans beaucoup
                                             * de villes.
                                             *
                                             * La ville vient de la commune, et
                                             * n'est plus « Kinshasa » ecrit en
                                             * dur : un restaurant de
                                             * Lubumbashi se serait cherche a
                                             * Kinshasa, et ne se serait jamais
                                             * trouve.
                                             */
                                            $commune = $get('town_id')
                                                ? Town::query()->with('city')->find($get('town_id'))
                                                : null;

                                            /*
                                             * DEUX ECHECS DIFFERENTS, DEUX
                                             * MESSAGES DIFFERENTS.
                                             *
                                             * Une adresse inconnue se corrige
                                             * en la precisant ; un service
                                             * qui refuse ne se corrige pas du
                                             * tout. Dire « introuvable » dans
                                             * le second cas enverrait
                                             * reecrire une adresse juste.
                                             */
                                            try {
                                                $position = app(Nominatim::class)->chercher(
                                                    $adresse,
                                                    trim(($commune?->title ?? '').' '.($commune?->city?->title ?? '')),
                                                );
                                            } catch (GeocodageIndisponible $e) {
                                                Notification::make()
                                                    ->title('Service de cartes indisponible')
                                                    ->body($e->getMessage().' Réessayez dans un moment, ou saisissez les coordonnées à la main.')
                                                    ->danger()
                                                    ->send();

                                                return;
                                            }

                                            if (! $position) {
                                                Notification::make()
                                                    ->title('Adresse introuvable')
                                                    ->body('OpenStreetMap ne connaît pas cette adresse. Précisez-la, ou saisissez les coordonnées à la main.')
                                                    ->warning()
                                                    ->send();

                                                return;
                                            }

                                            $set('location.lat', $position['lat']);
                                            $set('location.lng', $position['lng']);

                                            Notification::make()
                                                ->title('Position trouvée')
                                                ->body($position['libelle'])
                                                ->success()
                                                ->send();
                                        }),
                                ])->columnSpanFull(),

                                TextInput::make('location.lat')
                                    ->label('Latitude')
                                    ->numeric()
                                    ->minValue(-90)
                                    ->maxValue(90)
                                    ->helperText('Facultatif. Sans coordonnées, on utilise le centre de la commune.'),

                                TextInput::make('location.lng')
                                    ->label('Longitude')
                                    ->numeric()
                                    ->minValue(-180)
                                    ->maxValue(180),
                            ]),
                    ]),

                Section::make('Horaires d’ouverture')
                    ->icon('heroicon-o-clock')
                    ->description('Sans aucun créneau, le restaurant est ouvert en permanence.')
                    ->collapsible()
                    ->collapsed(fn (string $operation): bool => $operation === 'edit')
                    ->schema([
                        /*
                         * Aucun creneau par defaut : `Restaurant::estOuvert()`
                         * tient « aucun horaire saisi » pour ouvert en
                         * permanence, et le formulaire ouvrait pourtant un
                         * creneau vide ET obligatoire. Le seul etat que le
                         * modele traite comme normal etait donc celui qu'on ne
                         * pouvait pas enregistrer.
                         *
                         * Les trois champs restent obligatoires DANS un
                         * creneau ajoute : un creneau a moitie rempli ferme le
                         * restaurant le jour qu'il nomme sans dire quand il
                         * rouvre.
                         */
                        Repeater::make('openHours')
                            ->label('')
                            ->addActionLabel('Ajouter un créneau')
                            ->defaultItems(0)
                            ->itemLabel(fn (array $state): ?string => $state['day'] ?? null)
                            ->collapsible()
                            ->schema([
                                Select::make('day')
                                    ->label('Le jour')
                                    ->options([
                                        'lundi' => 'Lundi',
                                        'mardi' => 'Mardi',
                                        'mercredi' => 'Mercredi',
                                        'jeudi' => 'Jeudi',
                                        'vendredi' => 'Vendredi',
                                        'samedi' => 'Samedi',
                                        'dimanche' => 'Dimanche',
                                    ])
                                    ->native(false)
                                    ->required(),

                                TimePicker::make('startAt')
                                    ->label('Ouverture')
                                    ->seconds(false)
                                    ->required(),

                                TimePicker::make('endAt')
                                    ->label('Fermeture')
                                    ->seconds(false)
                                    ->required()
                                    ->helperText('Avant l’heure d’ouverture, la fermeture est le lendemain : 23:00 – 02:00 ferme à 2 h du matin.'),
                            ])
                            ->columns(3)
                            ->columnSpanFull(),
                    ]),

                Section::make('Bannière')
                    ->icon('heroicon-o-photo')
                    ->description('L’image affichée en tête de sa fiche, dans les applications.')
                    ->collapsible()
                    ->collapsed(fn (string $operation): bool => $operation === 'edit')
                    ->schema([
                        FileUpload::make('banniere')
                            ->label('')
                            ->disk('uploads_image')
                            ->image()
                            ->imagePreviewHeight('160')
                            ->acceptedFileTypes(['image/*'])
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('banniere')->disk('uploads_image'),
                TextColumn::make('name')
                    ->searchable(),
                TextColumn::make('phone')
                    ->searchable(),
                TextColumn::make('user.name')->label(__('Responsable'))
                    ->badge()
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
            ->headerActions([
                CreateAction::make(),
            ])
            ->emptyStateActions([
                CreateAction::make(),
            ]);
    }

    public static function getRecordSubNavigation(Page $page): array
    {
        return $page->generateNavigationItems([
            // ...
            EditRestaurant::class,
            ManageProducts::class,

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
            'index' => ListRestaurants::route('/'),
            'create' => CreateRestaurant::route('/create'),
            'edit' => EditRestaurant::route('/{record}/edit'),
            'manage' => ManageProducts::route('/{record}/manage'),
        ];
    }

    public static function toSlug($string): string
    {
        return Str::slug($string);
    }
}
