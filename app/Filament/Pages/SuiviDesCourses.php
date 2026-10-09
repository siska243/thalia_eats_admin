<?php

namespace App\Filament\Pages;

use App\Models\Booking;
use App\Models\Commande;
use App\Models\TrackOrder;
use App\Services\Suivi\SuiviPositions;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Le suivi des courses : repas et location, sur une carte OpenStreetMap.
 *
 * Deux vues avec le meme ecran, parce que c'est la meme donnee : une course
 * en cours se rafraichit toute seule, une course terminee reste consultable.
 * L'objectif annonce est de comprendre comment ameliorer le service, ce qui
 * demande de pouvoir relire ce qui s'est passe.
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

    protected static ?string $navigationLabel = 'Courses suivies';

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

    /** La course regardee, sous la forme « repas:12 ». */
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
    }

    public function updatedCourse(): void
    {
        $this->chargerLeTrace();
    }

    /** Appelee par le rafraichissement automatique, course en cours. */
    public function rafraichir(): void
    {
        $this->chargerLeTrace();
    }

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('exporter')
                ->label('Exporter en tableur')
                ->icon('heroicon-o-arrow-down-tray')
                // Les releves sont purges au-dela du delai de conservation :
                // l'export est ce qui permet de garder ce qu'on voulait
                // analyser. Il suit les filtres affiches, pour qu'on exporte
                // ce qu'on regarde.
                ->action(fn (): StreamedResponse => $this->exporter()),
        ];
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

    /** La commande ou la reservation designee par `$course`. */
    private function courseRegardee(): ?object
    {
        if (! $this->course || ! str_contains($this->course, ':')) {
            return null;
        }

        [$type, $id] = explode(':', $this->course, 2);

        $classe = self::TYPES[$type] ?? null;

        return $classe ? $classe::query()->whereKey($id)->first() : null;
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
