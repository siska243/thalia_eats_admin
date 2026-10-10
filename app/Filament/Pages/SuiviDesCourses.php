<?php

namespace App\Filament\Pages;

use App\Filament\Resources\BookingResource;
use App\Models\Booking;
use App\Models\Commande;
use App\Models\TrackOrder;
use App\Services\Suivi\SuiviPositions;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Le suivi des courses : repas et location, sur une carte OpenStreetMap.
 *
 * Un seul ecran, deux modes, parce que c'est la meme donnee lue a deux
 * echelles :
 *
 * 1. Ouvert avec une course (`?course=repas:12`, depuis le bouton « Suivre »
 *    d'une ligne de `BookingResource` ou de `CommandeResource`), il ne montre
 *    que cette course : sa fiche, son trace, sa duree, sa distance. C'est le
 *    chemin normal, et il part de la ou l'administrateur a deja la course
 *    sous les yeux — lui redemander de la retrouver dans une liste a part
 *    etait le reproche qui nous a ete fait.
 *
 * 2. Ouvert sans course, c'est la vue d'analyse : la periode, le type, la
 *    liste de ce qui a ete suivi et l'export. Elle sert l'autre objectif,
 *    comprendre comment ameliorer le service, et l'export est la condition
 *    posee a la purge a quatre-vingt-dix jours.
 *
 * Deux modes et non deux pages : c'est la meme requete, les memes filtres et
 * le meme export : les separer aurait duplique tout cela pour ne changer
 * qu'un titre.
 *
 * OpenStreetMap, et non Google Maps : aucune cle a prevoir, aucun secret a
 * commiter. Les tuiles sont servies par le navigateur de l'administrateur,
 * une carte a la fois.
 */
class SuiviDesCourses extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map';

    protected static string|\UnitEnum|null $navigationGroup = 'Suivi des courses';

    protected static ?string $title = 'Suivi des courses';

    protected static ?string $navigationLabel = 'Analyse des courses';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.suivi-des-courses';

    public const TYPE_REPAS = 'repas';

    public const TYPE_LOCATION = 'location';

    /** Les deux natures de course, et la classe qui les porte. */
    public const TYPES = [
        self::TYPE_REPAS => Commande::class,
        self::TYPE_LOCATION => Booking::class,
    ];

    public ?string $du = null;

    public ?string $au = null;

    public string $type = 'toutes';

    /**
     * La course regardee, sous la forme « repas:12 ».
     *
     * Dans l'URL : c'est elle qui fait de l'ecran une page de course, et le
     * lien reste partageable et rechargeable.
     */
    #[Url]
    public ?string $course = null;

    /**
     * Le trace de la course regardee.
     *
     * Propriete et non valeur calculee : Alpine la suit avec `@entangle` et
     * ne redessine que la ligne, sans reconstruire la carte ni redemander
     * ses tuiles a OpenStreetMap a chaque rafraichissement.
     *
     * @var array<string, mixed>
     */
    public array $trace = [];

    public function mount(): void
    {
        $this->du = now()->subDays(7)->toDateString();
        $this->au = now()->toDateString();

        // La page peut arriver deja pointee sur une course, par le bouton
        // « Suivre » : le trace doit etre pret avant le premier rendu.
        $this->chargerLeTrace();
    }

    public function updatedCourse(): void
    {
        $this->courseResolue = null;
        $this->chargerLeTrace();
    }

    /** Appelee par le rafraichissement automatique, course en cours. */
    public function rafraichir(): void
    {
        $this->chargerLeTrace();
    }

    /** Regarde-t-on une course precise, ou l'ensemble ? */
    public function modeCourse(): bool
    {
        return $this->courseRegardee() !== null;
    }

    public function getTitle(): string
    {
        $fiche = $this->fiche;

        return $fiche
            ? "Course {$fiche['reference']}"
            : 'Analyse des courses';
    }

    public function getSubheading(): ?string
    {
        return $this->fiche
            ? null
            : 'Les positions relevées sur la période, pour analyse et export. Le suivi d\'une course s\'ouvre depuis sa ligne, dans Réservations ou Commandes.';
    }

    /**
     * L'adresse du suivi d'une course donnee.
     *
     * Un seul endroit sait fabriquer la cle « repas:12 » : les deux
     * ressources s'y adressent, elles n'ecrivent pas le format a la main.
     */
    public static function urlPour(Model $course): string
    {
        $type = array_search($course::class, self::TYPES, true);

        return static::getUrl(['course' => "{$type}:{$course->getKey()}"]);
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('vueDEnsemble')
                ->label("Vue d'ensemble")
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn (): bool => $this->modeCourse())
                ->url(static::getUrl()),

            Action::make('exporter')
                ->label('Exporter en tableur')
                ->icon('heroicon-o-arrow-down-tray')
                // Les releves sont purges au-dela du delai de conservation :
                // l'export est ce qui permet de garder ce qu'on voulait
                // analyser. Il suit les filtres affiches, pour qu'on exporte
                // ce qu'on regarde — donc il vit avec eux, dans la vue
                // d'ensemble.
                ->visible(fn (): bool => ! $this->modeCourse())
                ->action(fn (): StreamedResponse => $this->exporter()),
        ];
    }

    /**
     * La fiche de la course regardee : de quoi la reconnaitre sans la chercher.
     *
     * @return array<string, mixed>|null
     */
    public function getFicheProperty(): ?array
    {
        $course = $this->courseRegardee();

        if (! $course) {
            return null;
        }

        return $course instanceof Booking
            ? $this->ficheDUneReservation($course)
            : $this->ficheDUneLivraison($course);
    }

    /** L'entete de la course : qui, quoi, ou en est-elle, et ce qu'a donne le trajet. */
    public function identite(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(4)
                ->components([
                    TextEntry::make('reference')
                        ->label('Référence')
                        ->copyable()
                        ->weight('bold')
                        ->state(fn (): string => $this->fiche['reference'] ?? '—'),

                    TextEntry::make('client')
                        ->label('Client')
                        ->state(fn (): string => $this->fiche['client'] ?? '—'),

                    TextEntry::make('origine')
                        ->label(fn (): string => $this->fiche['origine_label'] ?? 'Origine')
                        ->state(fn (): string => $this->fiche['origine'] ?? '—'),

                    TextEntry::make('etat')
                        ->label('État')
                        ->badge()
                        ->color(fn (): string => $this->fiche['etat_couleur'] ?? 'gray')
                        ->state(fn (): string => $this->fiche['etat'] ?? '—'),
                ]),

            Section::make()
                ->columns(4)
                ->components([
                    TextEntry::make('duree')
                        ->label('Durée du suivi')
                        ->icon('heroicon-m-clock')
                        ->size('lg')
                        ->state(fn (): string => isset($this->trace['duree_minutes'])
                            ? "{$this->trace['duree_minutes']} min"
                            : '—'),

                    TextEntry::make('distance')
                        ->label('Distance parcourue')
                        ->icon('heroicon-m-map')
                        ->size('lg')
                        ->helperText('À vol d\'oiseau entre relevés : un plancher, pas la distance routière.')
                        ->state(fn (): string => isset($this->trace['distance_km'])
                            ? number_format($this->trace['distance_km'], 2, ',', ' ').' km'
                            : '—'),

                    TextEntry::make('releves')
                        ->label('Relevés')
                        ->icon('heroicon-m-map-pin')
                        ->size('lg')
                        ->state(fn (): string => (string) count($this->trace['points'] ?? [])),

                    TextEntry::make('fenetre')
                        ->label('Période')
                        ->state(fn (): string => isset($this->trace['debut'])
                            ? "{$this->trace['debut']} → {$this->trace['fin']}"
                            : '—'),
                ]),
        ]);
    }

    /**
     * Les courses suivies, une ligne par course.
     *
     * Agregation en SQL : la table grandit de plusieurs releves par course et
     * par jour, et la parcourir en PHP pour compter finirait par couter plus
     * que l'ecran ne vaut.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCoursesProperty(): array
    {
        $lignes = $this->requeteFiltree()
            ->select([
                'suivable_type',
                'suivable_id',
                DB::raw('count(*) as releves'),
                DB::raw('min(created_at) as debut'),
                DB::raw('max(created_at) as fin'),
            ])
            ->groupBy('suivable_type', 'suivable_id')
            ->orderByDesc(DB::raw('max(created_at)'))
            ->limit(200)
            ->get();

        // Les references sont lues en deux requetes, pas une par ligne.
        $references = $this->references($lignes);

        return $lignes->map(function ($ligne) use ($references) {
            $type = array_search($ligne->suivable_type, self::TYPES, true) ?: 'inconnu';

            return [
                'cle' => "{$type}:{$ligne->suivable_id}",
                'type' => $type,
                'reference' => $references["{$ligne->suivable_type}:{$ligne->suivable_id}"] ?? "#{$ligne->suivable_id}",
                'releves' => (int) $ligne->releves,
                'debut' => Carbon::parse($ligne->debut),
                'fin' => Carbon::parse($ligne->fin),
            ];
        })->all();
    }

    /** Recalcule le trace de la course regardee. */
    private function chargerLeTrace(): void
    {
        $course = $this->courseRegardee();

        if (! $course) {
            $this->trace = [];

            return;
        }

        $points = TrackOrder::query()
            ->where('suivable_type', $course::class)
            ->where('suivable_id', $course->getKey())
            ->orderBy('id')
            ->get()
            ->map(fn (TrackOrder $releve) => [
                'lat' => (float) ($releve->location_delivery['lat'] ?? 0),
                'lng' => (float) ($releve->location_delivery['lng'] ?? 0),
                'at' => $releve->created_at?->toDateTimeString(),
            ])
            ->filter(fn (array $point) => $point['lat'] !== 0.0 || $point['lng'] !== 0.0)
            ->values()
            ->all();

        if ($points === []) {
            $this->trace = [];

            return;
        }

        $debut = Carbon::parse($points[0]['at']);
        $fin = Carbon::parse($points[count($points) - 1]['at']);

        $this->trace = [
            'points' => $points,
            /*
             * Les deux reperes que l'administrateur cherche : ou est le
             * chauffeur, et ou est le client. Le trace seul ne le disait pas,
             * il fallait deviner que le dernier point etait le vehicule et
             * que le client n'etait nulle part.
             *
             * Chauffeur : le dernier releve. Client : le point de prise en
             * charge pour une location, qui le porte en propre, et la
             * position geocodee une fois pour une commande de repas.
             */
            'chauffeur' => $points[count($points) - 1],
            'client' => $this->positionDuClient($course, $points),
            'distance_km' => round($this->distanceParcourue($points), 2),
            'duree_minutes' => (int) $debut->diffInMinutes($fin),
            'debut' => $debut->format('d/m/Y H:i'),
            'fin' => $fin->format('d/m/Y H:i'),
            /*
             * Une course encore active fait se rafraichir la carte toute
             * seule : c'est la vue « direct ». Une course finie ne bouge
             * plus, et interroger le serveur en boucle pour rien serait du
             * gaspillage — pour nous comme pour les serveurs de tuiles.
             */
            'en_cours' => app(SuiviPositions::class)->fenetreActive($course),
        ];
    }

    /**
     * Ou se trouve le client, selon la nature de la course.
     *
     * Une reservation porte ses coordonnees de prise en charge en propre.
     * Une commande de repas les fait geocoder une fois, au premier releve,
     * puis les recopie : on les relit donc sur le dernier releve.
     *
     * @param  array<int, array<string, mixed>>  $points
     * @return array<string, mixed>|null
     */
    private function positionDuClient(Model $course, array $points): ?array
    {
        if ($course instanceof Booking) {
            if ($course->pickup_lat === null || $course->pickup_lng === null) {
                return null;
            }

            return [
                'lat' => (float) $course->pickup_lat,
                'lng' => (float) $course->pickup_lng,
                'libelle' => $course->pickup_location ?: 'Prise en charge',
            ];
        }

        $dernier = TrackOrder::query()
            ->where('suivable_type', $course::class)
            ->where('suivable_id', $course->getKey())
            ->latest('id')
            ->first();

        $position = $dernier?->location_customer;

        if (! is_array($position) || ! isset($position['lat'], $position['lng'])) {
            return null;
        }

        return [
            'lat' => (float) $position['lat'],
            'lng' => (float) $position['lng'],
            'libelle' => $course->adresse_delivery ?: 'Livraison',
        ];
    }

    /** Le tableur des releves filtres, pour analyse hors ligne. */
    public function exporter(): StreamedResponse
    {
        $requete = $this->requeteFiltree()->orderBy('id');
        $nom = 'suivi-courses-'.now()->format('Y-m-d-His').'.xlsx';

        return response()->streamDownload(function () use ($requete) {
            $writer = new Writer;
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues([
                'Type', 'Référence', 'Horodatage', 'Latitude', 'Longitude',
            ]));

            $references = [];

            // Par lots : la table peut porter des centaines de milliers de
            // lignes, et les charger d'un coup epuiserait la memoire du
            // serveur au moment ou l'on tente justement de les sauver.
            $requete->chunkById(500, function ($releves) use ($writer, &$references) {
                foreach ($this->references($releves) as $cle => $reference) {
                    $references[$cle] = $reference;
                }

                foreach ($releves as $releve) {
                    $writer->addRow(Row::fromValues([
                        array_search($releve->suivable_type, self::TYPES, true) ?: 'inconnu',
                        $references["{$releve->suivable_type}:{$releve->suivable_id}"] ?? "#{$releve->suivable_id}",
                        $releve->created_at?->toDateTimeString(),
                        $releve->location_delivery['lat'] ?? null,
                        $releve->location_delivery['lng'] ?? null,
                    ]));
                }
            });

            $writer->close();
        }, $nom, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function requeteFiltree(): Builder
    {
        return TrackOrder::query()
            ->when($this->du, fn (Builder $q) => $q->whereDate('created_at', '>=', $this->du))
            ->when($this->au, fn (Builder $q) => $q->whereDate('created_at', '<=', $this->au))
            ->when(
                isset(self::TYPES[$this->type]),
                fn (Builder $q) => $q->where('suivable_type', self::TYPES[$this->type]),
            );
    }

    /** @var Model|false|null la course designee, resolue une fois par requete */
    private Model|false|null $courseResolue = null;

    /** La commande ou la reservation designee par `$course`. */
    private function courseRegardee(): ?Model
    {
        if ($this->courseResolue !== null && $this->courseResolue !== false) {
            return $this->courseResolue;
        }

        if (! $this->course || ! str_contains($this->course, ':')) {
            return null;
        }

        [$type, $id] = explode(':', $this->course, 2);

        $classe = self::TYPES[$type] ?? null;

        return $this->courseResolue = ($classe ? $classe::query()->whereKey($id)->first() : null) ?? false;
    }

    /** @return array<string, mixed> */
    private function ficheDUneReservation(Booking $booking): array
    {
        return [
            'reference' => (string) $booking->reference,
            'client' => (string) ($booking->user?->name ?? '—'),
            'origine_label' => 'Véhicule',
            'origine' => trim(($booking->vehicle?->name ?? '').' '.($booking->vehicle?->plate_number ? "({$booking->vehicle->plate_number})" : '')) ?: '—',
            'etat' => BookingResource::statusLabels()[$booking->statutEffectif()] ?? $booking->status,
            'etat_couleur' => match ($booking->statutEffectif()) {
                Booking::STATUS_CONFIRMED, Booking::STATUS_IN_PROGRESS => 'success',
                Booking::STATUS_PENDING_PAYMENT => 'warning',
                Booking::STATUS_LOST, Booking::STATUS_CANCELLED, Booking::STATUS_EXPIRED => 'danger',
                default => 'gray',
            },
        ];
    }

    /** @return array<string, mixed> */
    private function ficheDUneLivraison(Commande $commande): array
    {
        // Le restaurant se lit par le premier produit commande : la commande
        // ne le porte pas elle-meme. Une requete, pour une page a la fois.
        $restaurant = $commande->commande_products()
            ->with('product.restaurant')
            ->first()?->product?->restaurant?->name;

        return [
            'reference' => (string) $commande->refernce,
            'client' => (string) ($commande->user?->name ?? '—'),
            'origine_label' => 'Restaurant',
            'origine' => $restaurant ?: '—',
            'etat' => (string) ($commande->status?->title ?? '—'),
            'etat_couleur' => (int) $commande->status_id === 2 ? 'success' : 'gray',
        ];
    }

    /**
     * Les references lisibles des courses citees, en deux requetes.
     *
     * @param  iterable<object>  $lignes
     * @return array<string, string>
     */
    private function references(iterable $lignes): array
    {
        $parType = [];

        foreach ($lignes as $ligne) {
            $parType[$ligne->suivable_type][] = $ligne->suivable_id;
        }

        $references = [];

        foreach ($parType as $classe => $ids) {
            // `refernce` cote repas, `reference` cote location : la faute de
            // frappe est historique et fait partie du contrat, on ne la
            // corrige pas, on compose avec.
            $colonne = $classe === Commande::class ? 'refernce' : 'reference';

            if (! class_exists($classe)) {
                continue;
            }

            foreach ($classe::query()->whereKey(array_unique($ids))->get(['id', $colonne]) as $modele) {
                $references["{$classe}:{$modele->id}"] = (string) $modele->{$colonne};
            }
        }

        return $references;
    }

    /**
     * La distance parcourue, en kilometres.
     *
     * Somme des sauts entre releves consecutifs, par la formule de haversine.
     * Ce n'est pas la distance routiere : avec un releve toutes les cinq
     * minutes, les virages sont coupes et le chiffre est un plancher.
     *
     * @param  array<int, array{lat: float, lng: float}>  $points
     */
    private function distanceParcourue(array $points): float
    {
        $total = 0.0;

        for ($i = 1; $i < count($points); $i++) {
            $total += $this->haversine(
                $points[$i - 1]['lat'], $points[$i - 1]['lng'],
                $points[$i]['lat'], $points[$i]['lng'],
            );
        }

        return $total;
    }

    private function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $rayonTerrestre = 6371.0;

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return $rayonTerrestre * 2 * asin(min(1.0, sqrt($a)));
    }
}
