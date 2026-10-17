<?php

namespace App\Filament\Pages;

use App\Settings\ExpirationSettings;
use App\Settings\FoodSettings;
use App\Settings\NotificationSettings;
use App\Settings\ProspectionSettings;
use App\Settings\RentalSettings;
use App\Settings\SuiviSettings;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\SettingsPage;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Spatie\LaravelSettings\Settings;

/**
 * Tous les reglages de l'application, en un seul ecran.
 *
 * Ils vivaient dans quatre pages separees, reparties dans trois groupes de
 * navigation differents, et deux classes de reglages n'avaient aucun ecran du
 * tout — `FoodSettings` et `ProspectionSettings` n'etaient modifiables qu'en
 * base.
 *
 * Un seul ecran veut dire aussi une seule permission a gerer. C'est loin
 * d'etre un detail ici : une page dont la permission n'a pas ete generee est
 * invisible, meme pour un super_admin — `define_via_gate` est a `false` dans
 * la configuration de Shield, et le role ne contourne donc rien. C'est ce qui
 * avait vide le menu Location.
 *
 * La classe mere ne sait traiter qu'une seule classe de reglages : `fillForm`
 * et `save` sont donc redefinis. Tout le reste — bouton Enregistrer, alerte
 * de modifications non enregistrees, transaction, notification — vient d'elle.
 */
class Parametres extends SettingsPage
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string|\UnitEnum|null $navigationGroup = 'Paramètres';

    protected static ?string $title = 'Paramètres';

    protected static ?string $navigationLabel = 'Paramètres';

    protected static ?int $navigationSort = 1;

    /**
     * Exigee par la classe mere, et inutilisee : `fillForm` et `save` sont
     * redefinis et parcourent les cinq classes ci-dessous.
     */
    protected static string $settings = FoodSettings::class;

    /**
     * Un onglet par classe de reglages.
     *
     * La cle sert de chemin d'etat : les champs gardent leurs noms d'origine,
     * et la validation croisee de la location — `$get('hours_min')` — continue
     * de se resoudre dans son propre onglet.
     *
     * @var array<string, class-string<Settings>>
     */
    private const GROUPES = [
        'food' => FoodSettings::class,
        'rental' => RentalSettings::class,
        'expiration' => ExpirationSettings::class,
        'suivi' => SuiviSettings::class,
        'prospection' => ProspectionSettings::class,
        'notification' => NotificationSettings::class,
    ];

    protected function fillForm(): void
    {
        $this->callHook('beforeFill');

        $donnees = [];

        foreach (self::GROUPES as $cle => $classe) {
            $donnees[$cle] = app($classe)->toArray();
        }

        $this->form->fill($donnees);

        $this->callHook('afterFill');
    }

    public function save(): void
    {
        if (! $this->canEdit()) {
            return;
        }

        $this->beginDatabaseTransaction();

        try {
            $this->callHook('beforeValidate');
            $donnees = $this->form->getState();
            $this->callHook('afterValidate');

            $this->callHook('beforeSave');

            /*
             * Les cinq classes sont enregistrees dans la meme transaction.
             *
             * Sans elle, une erreur sur la quatrieme laisserait les trois
             * premieres ecrites : l'ecran montrerait un etat que la base ne
             * porte qu'a moitie.
             */
            foreach (self::GROUPES as $cle => $classe) {
                $reglages = app($classe);
                $reglages->fill(self::sansNullInterdit($classe, $donnees[$cle] ?? []));
                $reglages->save();
            }

            $this->callHook('afterSave');
        } catch (\Throwable $exception) {
            $this->rollBackDatabaseTransaction();

            throw $exception;
        }

        $this->commitDatabaseTransaction();

        $this->rememberData();

        $this->getSavedNotification()?->send();
    }

    /**
     * Retire les `null` que la classe de reglages visee n'accepte pas.
     *
     * Filament rend `null` tout champ texte vide : `HasState::getState()`
     * transforme une chaine blanche en `null`. Les proprietes de reglages sont
     * declarees `string` non nullable — un champ facultatif qu'on vide levait
     * donc un `\TypeError` dans `Settings::fill()`.
     *
     * Et ce `TypeError` etait invisible : Livewire le journalise puis rend un
     * **419 generique** quand `app.debug` est a faux
     * (`HandleRequests::handleUpdate`). En production, vider le numero WhatsApp
     * faisait echouer l'enregistrement sans aucun message — l'ecran repondait
     * 419, qu'on lit spontanement comme une session perdue.
     *
     * La garde est ici, et non champ par champ, parce que tout champ texte
     * facultatif ajoute plus tard retomberait exactement dans le meme piege.
     * Une chaine non nullable devient '' ; tout autre type non nullable reste
     * a sa valeur actuelle plutot que d'ecrire une valeur inventee.
     *
     * @param  class-string<Settings>  $classe
     * @param  array<string, mixed>  $donnees
     * @return array<string, mixed>
     */
    private static function sansNullInterdit(string $classe, array $donnees): array
    {
        foreach ($donnees as $nom => $valeur) {
            if ($valeur !== null) {
                continue;
            }

            $propriete = new \ReflectionProperty($classe, $nom);
            $type = $propriete->getType();

            if ($type === null || $type->allowsNull()) {
                continue;
            }

            if ($type instanceof \ReflectionNamedType && $type->getName() === 'string') {
                $donnees[$nom] = '';

                continue;
            }

            unset($donnees[$nom]);
        }

        return $donnees;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make('Parametres')
                ->columnSpanFull()
                ->tabs([
                    Tab::make('Livraison des repas')
                        ->icon('heroicon-o-map-pin')
                        ->statePath('food')
                        ->schema([

                            Section::make('Distance de livraison')
                                ->description("Au-dela de cette distance entre le restaurant et l'adresse de livraison, la commande est refusee. Une ville peut resserrer ce rayon depuis sa propre fiche.")
                                ->schema([
                                    TextInput::make('max_delivery_radius_km')
                                        ->label('Rayon maximal')
                                        ->suffix('km')
                                        ->numeric()
                                        ->minValue(1)
                                        ->maxValue(500)
                                        ->required()
                                        ->helperText("S'applique aux villes qui n'ont pas leur propre rayon."),

                                    Textarea::make('out_of_radius_message')
                                        ->label('Message de refus')
                                        ->rows(3)
                                        ->required()
                                        ->helperText('Trois emplacements disponibles : :restaurant, :distance et :rayon. Ils sont remplaces par le nom du restaurant, la distance reelle et le rayon applique.')
                                        ->columnSpanFull(),
                                ])->columns(2),

                            Section::make('Ville ou distance')
                                ->description('Kinshasa fait plus de quarante kilomètres de large : un rayon de trente y refuse des livraisons parfaitement normales.')
                                ->schema([
                                    Toggle::make('city_first')
                                        ->label('La ville prime sur la distance')
                                        ->helperText("Activé, deux adresses d'une même ville se livrent quelle que soit la distance. Le rayon ci-dessus ne sert plus que de repli : quand les deux villes diffèrent, ou quand l'une des deux n'est pas renseignée."),
                                ]),

                            Section::make('Stock des plats')
                                ->description("Le suivi se decide PLAT PAR PLAT, depuis la fiche du plat. Cet interrupteur ne sert qu'a tout eteindre d'un coup.")
                                ->schema([
                                    Toggle::make('stock_actif')
                                        ->label('Tenir compte du stock')
                                        ->helperText("Eteint, plus aucun plat n'est annonce epuise et aucune commande n'est refusee pour cause de rupture, y compris ceux dont le stock est suivi. Le decompte, lui, continue : les quantites restent justes le jour ou vous rallumez. A n'eteindre qu'en cas de probleme."),
                                ]),

                            Section::make('Position du client')
                                ->description("Par defaut, seule l'adresse de livraison compte : on peut commander pour sa famille a Kinshasa depuis n'importe ou.")
                                ->schema([
                                    Toggle::make('use_client_position')
                                        ->label('Tenir compte de la position du client')
                                        ->helperText("Active, un client doit aussi se trouver a portee du restaurant pour commander : depuis l'etranger, les restaurants de Kinshasa apparaissent grises. L'application lui demande alors sa position ; s'il refuse, seule l'adresse de livraison compte."),
                                ]),
                        ]),

                    Tab::make('Location')
                        ->icon('heroicon-o-truck')
                        ->statePath('rental')
                        ->schema([

                            Section::make('Paiement')
                                ->columns(2)
                                ->schema([
                                    TextInput::make('deposit_percentage')
                                        ->label('Acompte')
                                        ->suffix('%')
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue(100)
                                        ->required()
                                        ->helperText('Part réglée en ligne à la réservation. Le solde est encaissé en espèces par le chauffeur.'),

                                    TextInput::make('refund_percentage')
                                        ->label('Remboursement')
                                        ->suffix('%')
                                        ->numeric()
                                        ->minValue(0)
                                        ->maxValue(100)
                                        ->required()
                                        ->helperText("Part rendue quand une réservation est annulée, ou ratée parce qu'un autre client a payé avant. Le montant est calculé et enregistré ; le virement reste manuel."),
                                ]),

                            Section::make('Course')
                                ->columns(2)
                                ->schema([
                                    TextInput::make('minimum_hours')
                                        ->label('Durée minimale facturée')
                                        ->suffix('heures')
                                        ->numeric()
                                        ->minValue(0.5)
                                        ->step(0.5)
                                        ->required()
                                        ->helperText('Toute heure entamée est due.'),

                                    TextInput::make('pickup_code_max_attempts')
                                        ->label('Essais sur le code de prise en charge')
                                        ->numeric()
                                        ->minValue(1)
                                        ->maxValue(10)
                                        ->required()
                                        ->helperText('Au-delà, le code se bloque et seul un administrateur peut le débloquer.'),
                                ]),

                            Section::make('Durée de location')
                                ->description("Les bornes proposées par l'application, et refusées par l'API au-delà. Une course de 24 heures est une journée : le plafond horaire ne dépasse pas 23.")
                                ->columns(2)
                                ->schema([
                                    TextInput::make('hours_min')
                                        ->label('Durée minimale à l\'heure')
                                        ->suffix('heures')
                                        ->numeric()
                                        ->integer()
                                        ->minValue(1)
                                        ->maxValue(23)
                                        ->live()
                                        ->required()
                                        ->helperText('Sous cette durée, la réservation à l\'heure est refusée.'),

                                    TextInput::make('hours_max')
                                        ->label('Durée maximale à l\'heure')
                                        ->suffix('heures')
                                        ->numeric()
                                        ->integer()
                                        // Jamais sous le minimum, et jamais au-dessus de 23 :
                                        // 24 heures sont une journee, qui se facture au tarif
                                        // journalier.
                                        ->minValue(fn (Get $get): int => max(1, (int) $get('hours_min')))
                                        ->maxValue(23)
                                        ->required()
                                        ->helperText('Au-delà, le client doit réserver à la journée. 23 au maximum.'),

                                    TextInput::make('days_min')
                                        ->label('Durée minimale à la journée')
                                        ->suffix('jours')
                                        ->numeric()
                                        ->integer()
                                        ->minValue(1)
                                        ->live()
                                        ->required()
                                        ->helperText('Sous cette durée, la réservation à la journée est refusée.'),

                                    TextInput::make('days_max')
                                        ->label('Durée maximale à la journée')
                                        ->suffix('jours')
                                        ->numeric()
                                        ->integer()
                                        ->minValue(fn (Get $get): int => max(1, (int) $get('days_min')))
                                        ->required()
                                        ->helperText('Au-delà, la location se négocie de la main à la main.'),

                                    TextInput::make('lead_time_minutes')
                                        ->label('Délai de prévenance')
                                        ->suffix('minutes')
                                        ->numeric()
                                        ->integer()
                                        ->minValue(0)
                                        ->required()
                                        ->helperText("Délai minimal entre la réservation et le départ. L'API refuse un départ passé ou trop proche. Le back-office, lui, reste libre : une course prise au comptoir peut partir tout de suite."),
                                ]),

                            Section::make('Marque et contact')
                                ->columns(2)
                                ->schema([
                                    TextInput::make('brand_food')
                                        ->label('Nom de la restauration')
                                        ->required()
                                        ->maxLength(255)
                                        ->helperText("Affiché par l'application pour l'univers des repas."),

                                    TextInput::make('brand_rental')
                                        ->label('Nom de la location')
                                        ->required()
                                        ->maxLength(255)
                                        ->helperText("Affiché par l'application pour l'univers des véhicules."),

                                    TextInput::make('whatsapp_number')
                                        ->label('Numéro WhatsApp')
                                        ->tel()
                                        ->maxLength(255)
                                        ->helperText('Au format international, par exemple +243900000000. Laissé vide, le bouton WhatsApp ne sert à rien.'),

                                    Textarea::make('out_of_zone_message')
                                        ->label('Message hors zone')
                                        ->rows(3)
                                        ->required()
                                        ->maxLength(500)
                                        ->columnSpanFull()
                                        ->helperText('Montré quand la commune de prise en charge n\'est pas desservie, et renvoyé tel quel par l\'API à la réservation refusée.'),
                                ]),
                        ]),

                    Tab::make("Délai d'expiration")
                        ->icon('heroicon-o-clock')
                        ->statePath('expiration')
                        ->schema([

                            Section::make('Règlement')
                                ->description('Vaut pour les commandes de repas comme pour les réservations de véhicule : la règle annoncée au client est la même des deux côtés.')
                                ->schema([
                                    TextInput::make('minutes')
                                        ->label('Délai accordé pour régler')
                                        ->suffix('minutes')
                                        ->numeric()
                                        ->integer()
                                        ->minValue(1)
                                        ->required()
                                        ->helperText("Passé ce délai, la commande ou la réservation non payée expire et cesse de bloquer le client. Le compte à rebours affiché dans l'application suit cette valeur."),
                                ]),
                        ]),

                    Tab::make('Suivi des courses')
                        ->icon('heroicon-o-signal')
                        ->statePath('suivi')
                        ->schema([

                            Section::make('Enregistrement des positions')
                                ->description("Les applications émettent à leur rythme : c'est le serveur qui décide ce qui est conservé.")
                                ->columns(2)
                                ->schema([
                                    TextInput::make('interval_minutes')
                                        ->label('Intervalle entre deux relevés')
                                        ->suffix('minutes')
                                        ->numeric()
                                        ->integer()
                                        ->minValue(1)
                                        ->required()
                                        ->helperText("Un point qui arrive plus tôt que ça est refusé. Descendre cette valeur affine le tracé et remplit la table d'autant plus vite."),

                                    TextInput::make('stale_minutes')
                                        ->label('Position considérée comme périmée')
                                        ->suffix('minutes')
                                        ->numeric()
                                        ->integer()
                                        ->minValue(1)
                                        ->required()
                                        ->helperText("Au-delà de ce silence, le client ne voit plus de chauffeur sur sa carte. Montrer une position d'il y a une heure ferait attendre une voiture qui n'arrive pas."),

                                    TextInput::make('retention_days')
                                        ->label('Conservation des relevés')
                                        ->suffix('jours')
                                        ->numeric()
                                        ->integer()
                                        ->minValue(1)
                                        ->required()
                                        ->helperText('Passé ce délai, les relevés sont supprimés chaque nuit. Exportez ce que vous voulez analyser avant.'),
                                ]),
                        ]),

                    Tab::make('Prospection')
                        ->icon('heroicon-o-envelope')
                        ->statePath('prospection')
                        ->schema([
                            /*
                             * Jamais exposee jusqu'ici : l'adresse qui recoit
                             * les demandes de partenariat ne se changeait
                             * qu'en base.
                             */
                            /*
                             * Facultative, et c'est voulu.
                             *
                             * `ProspectionSettings` le dit : vide, aucun
                             * e-mail n'est envoye et le back-office reste la
                             * seule porte. La rendre obligatoire bloquerait
                             * l'enregistrement des QUATRE autres onglets pour
                             * une adresse que personne n'a jamais saisie.
                             */
                            TextInput::make('email_destinataire')
                                ->label('Adresse qui reçoit les demandes de partenariat')
                                ->email()
                                ->maxLength(255)
                                /*
                                 * `ProspectionSettings::$email_destinataire` est
                                 * un `string`, pas un `?string` : un champ vide
                                 * rend `null` et l'affectation leve. On convertit
                                 * ici plutot que d'assouplir le type d'un reglage
                                 * que d'autres lisent.
                                 *
                                 * Le parametre DOIT s'appeler `$state` : Filament
                                 * injecte les closures par le NOM du parametre,
                                 * pas par sa position. Mal nomme, il recoit null
                                 * et l'adresse saisie se perd en silence.
                                 */
                                ->dehydrateStateUsing(fn (?string $state): string => (string) $state)
                                ->helperText('Les formulaires « Devenir restaurant partenaire » et « Roulez avec nous » arrivent à cette adresse. Laissée vide, aucun e-mail n\'est envoyé : les demandes restent consultables dans « Demandes de partenariat ».'),
                        ]),

                    Tab::make('Notifications de commande')
                        ->icon('heroicon-o-bell-alert')
                        ->statePath('notification')
                        ->schema([
                            Section::make('Qui est prévenu quand une commande est payée')
                                ->description("Avant, une commande payée n'envoyait qu'une notification au restaurateur, et seulement s'il avait installé l'application. Ni vous ni le client n'appreniez rien.")
                                ->schema([
                                    Textarea::make('emails_commande')
                                        ->label('Adresses de l’équipe')
                                        ->rows(3)
                                        ->placeholder("commandes@thaliaeats.com\ndirection@thaliaeats.com")
                                        /*
                                         * Une zone de texte et non un
                                         * `repeater` : on y colle trois
                                         * adresses d'un coup, et un
                                         * repeater demanderait trois clics
                                         * pour la meme chose.
                                         *
                                         * `NotificationSettings` separe et
                                         * VALIDE a la lecture : une adresse
                                         * mal saisie est ignoree sans
                                         * empecher l'envoi aux autres.
                                         */
                                        ->dehydrateStateUsing(fn (?string $state): string => (string) $state)
                                        ->helperText('Une adresse par ligne, ou séparées par des virgules. Laissé vide, aucun e-mail ne part à l’équipe — la cloche du back-office continue de signaler les commandes.'),

                                    Toggle::make('copie_client')
                                        ->label('Envoyer une copie au client')
                                        ->helperText('Le récapitulatif de sa commande, à l’adresse de son compte.'),

                                    Toggle::make('copie_restaurant')
                                        ->label('Envoyer une copie au restaurant')
                                        ->helperText('En plus de la notification sur son téléphone, qui ne part que s’il a installé l’application.'),
                                ]),
                        ]),
                ]),
        ]);
    }
}
