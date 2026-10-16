<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Restaurant extends Model
{
    use HasFactory;

    protected $casts = [
        'openHours' => 'array',
        'location' => 'array',
    ];

    /**
     * Les jours tels qu'ils sont saisis dans l'administration, dans l'ordre
     * de `Carbon::dayOfWeek` : dimanche vaut zero.
     */
    private const JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];

    /**
     * Le restaurant prend-il une commande a cet instant ?
     *
     * **La regle vit ici, une seule fois.** Elle etait ecrite dans les deux
     * applications et nulle part sur le serveur : le site affichait « Ferme »
     * et laissait quand meme payer, le mobile grisait son bouton, et un appel
     * direct a l'API passait dans les deux cas. Un repas etait regle que
     * personne n'allait preparer.
     *
     * Sans horaires saisis, la reponse est **oui**. Un restaurateur qui a
     * oublie de les remplir ne doit pas se retrouver ferme sans le savoir :
     * c'est deja la regle des deux clients, et elle ne se contredit pas ici.
     *
     * Un creneau dont la fermeture precede l'ouverture passe minuit
     * (22:00 - 02:00), cas courant en restauration.
     */
    public function estOuvert(?Carbon $maintenant = null): bool
    {
        $creneaux = $this->openHours;

        if (empty($creneaux)) {
            return true;
        }

        $maintenant ??= now();
        $minute = $maintenant->hour * 60 + $maintenant->minute;

        /*
         * Le creneau de la VEILLE d'abord, quand il passe minuit.
         *
         * « 23h - 2h, c'est-a-dire le lendemain. » A une heure du matin un
         * mardi, le creneau qui tient le restaurant ouvert est celui du lundi :
         * ne regarder que le jour courant fermait un restaurant que son propre
         * creneau tenait ouvert jusqu'a 2h.
         */
        $veille = $this->creneauDu($creneaux, self::JOURS[($maintenant->dayOfWeek + 6) % 7]);
        $debutVeille = self::enMinutes($veille['startAt'] ?? null);
        $finVeille = self::enMinutes($veille['endAt'] ?? null);

        if ($debutVeille !== null && $finVeille !== null
            && $finVeille < $debutVeille && $minute < $finVeille) {
            return true;
        }

        $creneau = $this->creneauDu($creneaux, self::JOURS[$maintenant->dayOfWeek]);

        if (! $creneau) {
            return false;
        }

        $debut = self::enMinutes($creneau['startAt'] ?? null);
        $fin = self::enMinutes($creneau['endAt'] ?? null);

        if ($debut === null || $fin === null) {
            return true;
        }

        return $fin > $debut
            ? $minute >= $debut && $minute < $fin
            // Passe minuit : ouvert apres l'ouverture OU avant la fermeture.
            : $minute >= $debut || $minute < $fin;
    }

    /** Le creneau saisi pour ce jour, s'il y en a un. */
    private function creneauDu(array $creneaux, string $jour): ?array
    {
        return collect($creneaux)->first(
            fn ($c) => strtolower((string) ($c['day'] ?? $c['Jour'] ?? '')) === $jour
        );
    }

    /** L'heure de reouverture du jour, quand il y en a une. */
    public function ouvreA(?Carbon $maintenant = null): ?string
    {
        $creneaux = $this->openHours;

        if (empty($creneaux)) {
            return null;
        }

        $maintenant ??= now();
        $creneau = $this->creneauDu($creneaux, self::JOURS[$maintenant->dayOfWeek]);

        $debut = self::enMinutes($creneau['startAt'] ?? null);

        if ($debut === null) {
            return null;
        }

        return $maintenant->hour * 60 + $maintenant->minute < $debut
            ? substr((string) $creneau['startAt'], 0, 5)
            : null;
    }

    private static function enMinutes(?string $heure): ?int
    {
        if (! $heure) {
            return null;
        }

        [$h, $m] = array_pad(explode(':', $heure), 2, '0');

        return is_numeric($h) && is_numeric($m) ? ((int) $h) * 60 + (int) $m : null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function town(): BelongsTo
    {
        return $this->belongsTo(Town::class, 'town_id');
    }

    public function product(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function sub_categorie_product(): BelongsTo
    {
        return $this->belongsTo(SubCategoryProduct::class);
    }
}
