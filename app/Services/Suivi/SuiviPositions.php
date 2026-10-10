<?php

namespace App\Services\Suivi;

use App\Models\Booking;
use App\Models\Commande;
use App\Models\TrackOrder;
use App\Settings\SuiviSettings;
use App\Wrappers\Geocode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Le seul endroit qui ecrit un releve de position.
 *
 * Deux raisons de refuser un point, et elles vivent ici parce qu'elles
 * valent pour les repas comme pour la location :
 *
 * 1. La cadence. `track_orders` accumulait sans aucune limite : sur la
 *    commande 41, dix-neuf releves avec des ecarts de 4, 1 et 2 secondes.
 *    L'application livreur emet a son rythme, et le parc deja installe ne se
 *    met pas a jour sur commande — un garde ecrit dans le telephone
 *    n'arreterait rien avant le prochain build EAS. Seul le serveur peut
 *    arreter l'encombrement aujourd'hui.
 *
 * 2. La fenetre active. Hors course, une position ne sert a personne et
 *    renseigne sur les deplacements d'un chauffeur qui n'est plus en
 *    service.
 */
class SuiviPositions
{
    public function __construct(private readonly SuiviSettings $settings) {}

    /**
     * Enregistre la position d'une course, si elle a le droit d'exister.
     *
     * @param  array{lat: float|string, lng: float|string}  $position
     * @return bool vrai si le releve a ete ecrit
     */
    public function enregistrer(Model $course, array $position): bool
    {
        if (! $this->fenetreActive($course)) {
            return false;
        }

        if (! $this->cadenceRespectee($course)) {
            return false;
        }

        $this->releves($course)->create([
            'location_delivery' => [
                'lat' => (float) $position['lat'],
                'lng' => (float) $position['lng'],
            ],
            // Pour les repas seulement : la position du client est geocodee
            // une fois, au premier releve, puis recopiee. Une reservation,
            // elle, porte deja ses coordonnees de prise en charge.
            'location_customer' => $course instanceof Commande
                ? $this->positionDuClient($course)
                : null,
        ]);

        return true;
    }

    /**
     * La course est-elle dans sa fenetre de suivi ?
     *
     * Repas : de la prise en charge par le livreur jusqu'a la livraison, ce
     * que dit `status_id` 2 — la meme condition que celle deja appliquee a
     * la remontee de position des livreurs.
     *
     * Location : du paiement de l'acompte jusqu'a la fin de course, soit les
     * statuts qui disent une course engagee.
     */
    public function fenetreActive(Model $course): bool
    {
        return match (true) {
            $course instanceof Commande => (int) $course->status_id === 2,
            $course instanceof Booking => in_array($course->statutEffectif(), Booking::COURSE_ENGAGEE, true),
            default => false,
        };
    }

    /** Le dernier releve connu d'une course. */
    public function dernier(Model $course): ?TrackOrder
    {
        return $this->releves($course)->latest('id')->first();
    }

    /**
     * Le dernier releve, s'il est encore credible.
     *
     * Montrer un chauffeur la ou il etait il y a une heure est pire que ne
     * rien montrer : le client attend une voiture qui n'arrive pas.
     */
    public function dernierFrais(Model $course): ?TrackOrder
    {
        $dernier = $this->dernier($course);

        if (! $dernier || ! $dernier->created_at) {
            return null;
        }

        return $dernier->created_at->greaterThan(now()->subMinutes($this->settings->stale_minutes))
            ? $dernier
            : null;
    }

    private function cadenceRespectee(Model $course): bool
    {
        $dernier = $this->dernier($course);

        if (! $dernier || ! $dernier->created_at) {
            return true;
        }

        return $dernier->created_at->lessThanOrEqualTo(
            now()->subMinutes($this->settings->interval_minutes),
        );
    }

    private function releves(Model $course): MorphMany
    {
        return $course->morphMany(TrackOrder::class, 'suivable');
    }

    /**
     * La position du client d'une commande, geocodee une seule fois.
     *
     * L'appel au geocodeur est payant et lent : le premier releve le fait, les
     * suivants recopient. C'est le comportement d'origine, deplace ici avec
     * le reste de l'ecriture.
     *
     * @return array<string, mixed>|null
     */
    private function positionDuClient(Commande $commande): ?array
    {
        $precedent = $this->dernier($commande);

        if ($precedent) {
            return $precedent->location_customer;
        }

        return Geocode::getLatLngByAddress(
            "{$commande->adresse_delivery}, {$commande->town?->title}",
        );
    }
}
