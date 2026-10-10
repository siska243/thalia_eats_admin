<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Une demande de partenariat, restaurant ou livreur.
 *
 * Ce n'est pas une inscription : rien n'est cree pour le demandeur, aucun
 * compte, aucun acces. La societe rappelle.
 */
class Prospect extends Model
{
    use HasFactory;

    public const TYPE_RESTAURANT = 'restaurant';

    public const TYPE_LIVREUR = 'livreur';

    public const TYPES = [self::TYPE_RESTAURANT, self::TYPE_LIVREUR];

    /**
     * L'avancement de la prospection.
     *
     * Les cinq etats sont ecrits ici, une fois : l'ecran d'administration, le
     * filtre et la pastille les lisent tous les trois, et trois listes
     * auraient diverge au premier ajout.
     */
    public const STATUT_NOUVELLE = 'nouvelle';

    public const STATUT_CONTACTEE = 'contactee';

    public const STATUT_DISCUSSION = 'discussion';

    public const STATUT_ACCEPTEE = 'acceptee';

    public const STATUT_REFUSEE = 'refusee';

    public const STATUTS = [
        self::STATUT_NOUVELLE => 'Nouvelle',
        self::STATUT_CONTACTEE => 'Contactée',
        self::STATUT_DISCUSSION => 'En discussion',
        self::STATUT_ACCEPTEE => 'Acceptée',
        self::STATUT_REFUSEE => 'Refusée',
    ];

    protected $guarded = [];

    /*
     * Le statut par defaut est declare ici EN PLUS de la base.
     *
     * Une colonne `default` n'est appliquee que par MySQL, a l'insertion :
     * l'instance en memoire garde `null` jusqu'a une relecture. Le crochet
     * ci-dessous comparait donc `null` a « nouvelle », concluait que la
     * demande avait deja ete traitee, et datait le contact a la creation.
     * Meme piege que sur `Booking`, ou il est documente.
     */
    protected $attributes = [
        'statut' => self::STATUT_NOUVELLE,
    ];

    protected $casts = [
        'details' => 'array',
        'contacte_at' => 'datetime',
    ];

    /** Les demandes que personne n'a encore traitees. */
    public function scopeATraiter(Builder $query): Builder
    {
        return $query->where('statut', self::STATUT_NOUVELLE);
    }

    /**
     * La date de premier contact suit le statut, elle ne se saisit pas.
     *
     * Un administrateur qui passe une demande en « contactée » sans penser a
     * la date laissait une ligne sans trace de quand l'appel a eu lieu. Meme
     * motif que `cancel_at` sur les commandes, qui a deja produit des lignes
     * incoherentes ici.
     */
    protected static function booted(): void
    {
        static::saving(function (self $prospect) {
            $statut = $prospect->statut ?? self::STATUT_NOUVELLE;

            if ($statut !== self::STATUT_NOUVELLE && $prospect->contacte_at === null) {
                $prospect->contacte_at = now();
            }
        });
    }
}
