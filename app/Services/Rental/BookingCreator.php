<?php

namespace App\Services\Rental;

use App\Exceptions\BookingRefusee;
use App\Models\Booking;
use App\Models\CourseType;
use App\Models\Town;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Cree une reservation a partir d'une demande client.
 *
 * Le client envoie un vehicule, un creneau et un lieu. Jamais un montant : le
 * prix est refait ici, comme il l'est deja dans l'administration.
 *
 * La reservation nait en attente de paiement et ne retient rien : tant que son
 * client n'est pas parti payer, le vehicule reste proposable. C'est
 * `DepositPayment` qui retient le creneau, sous verrou, au moment ou le client
 * engage l'acompte, et `BookingConfirmation` qui tranche a l'encaissement.
 */
class BookingCreator
{
    public function __construct(
        private readonly BookingPricing $pricing,
        private readonly VehicleAvailability $availability,
    ) {}

    /**
     * @param  array<string, mixed>  $details
     *
     * @throws BookingRefusee
     */
    public function create(
        User $user,
        Vehicle $vehicle,
        Carbon $startsAt,
        Carbon $endsAt,
        array $details = [],
        string $mode = BookingPricing::MODE_HOURLY,
    ): Booking {
        if (! $vehicle->is_active) {
            throw new BookingRefusee(BookingRefusee::VEHICULE_INTROUVABLE);
        }

        /*
         * Une seule reservation non reglee a la fois.
         *
         * Sans cette regle, un client pouvait empiler les reservations
         * impayees : elles ne retiennent rien, mais elles remplissent
         * l'administration, et le jour ou il en paie une, personne ne sait
         * laquelle il voulait.
         *
         * Les reservations deja perimees sont expirees ici, sur-le-champ, et
         * ne bloquent pas : laisser le client attendre la tache planifiee le
         * priverait de reserver pendant un quart d'heure, sans lui dire
         * pourquoi ni combien de temps.
         */
        $this->expirerLesReservationsPerimees($user);

        $bloquante = Booking::query()
            ->where('user_id', $user->id)
            ->enAttenteDePaiement()
            ->oldest()
            ->first();

        if ($bloquante) {
            throw new BookingRefusee(
                BookingRefusee::RESERVATION_NON_REGLEE,
                "Votre réservation #{$bloquante->reference} n'est pas encore réglée. "
                ."Payez l'acompte ou annulez-la avant d'en réserver une autre.",
            );
        }

        /*
         * La location ne dessert que certaines communes.
         *
         * Le controle est ici, pas dans la validation de la requete : il
         * porte sur l'etat commercial de la commune, qui change en
         * administration, et il doit valoir pour tout appelant du service,
         * API comme back-office.
         *
         * Pas de commune transmise, pas de refus : les applications deja
         * installees n'en envoient pas, et les bloquer ferait plus de degats
         * qu'une course prise hors zone et rattrapee par telephone.
         */
        $townId = $details['pickup_town_id'] ?? null;

        if ($townId && ! Town::query()->whereKey($townId)->value('rental_available')) {
            throw new BookingRefusee(BookingRefusee::HORS_ZONE);
        }

        /*
         * Le vehicule travaille dans sa ville, et nulle part ailleurs.
         *
         * Le catalogue, lui, reste MONDIAL : « ils peuvent voir les vehicules
         * disponibles qui se trouvent dans tous les pays mais ils peuvent
         * commander que pour la course dans le pays d'origine de la voiture ».
         * C'est donc ici qu'on refuse, pas a l'affichage.
         *
         * APRES le controle de la commune : une commune fermee prime, parce
         * que son refus envoie vers WhatsApp quand l'autre envoie vers un
         * autre vehicule.
         *
         * Un vehicule sans ville — tout le parc existant — passe : ne rien
         * savoir de son rattachement ne doit pas fermer la location.
         */
        $ville = $townId ? Town::query()->whereKey($townId)->value('city_id') : null;

        if ($ville && $vehicle->city_id && (int) $ville !== (int) $vehicle->city_id) {
            throw new BookingRefusee(
                BookingRefusee::HORS_VILLE,
                "Ce véhicule effectue uniquement des courses à {$vehicle->city->title}.",
            );
        }

        /*
         * Les communes d'ou ce vehicule sort.
         *
         * Vide, il accepte toute sa ville : « aucune commune selectionnee, on
         * prend toutes les communes ». APRES le controle de ville, qui prime —
         * une commune cochee d'une autre ville ne rouvre rien, et ce cas
         * arrive des qu'on change la ville sans revoir les communes.
         */
        if ($townId && ! $vehicle->dessertLaCommune((int) $townId)) {
            $servies = $vehicle->towns()->orderBy('title')->pluck('title')->implode(', ');

            throw new BookingRefusee(
                BookingRefusee::HORS_COMMUNE,
                "Ce véhicule prend ses courses uniquement à : {$servies}.",
            );
        }

        /*
         * Les regles du type de course.
         *
         * Elles sont lues SUR LA LIGNE du referentiel, jamais deduites d'un
         * code : « le type c'est un referentiel », et un `if ($code === 'B')`
         * ici rendrait impossible d'ajouter un quatrieme type sans livraison.
         *
         * Sans type transmis, aucune regle ne s'applique : un vehicule qui
         * n'en propose aucun se reserve comme avant, et les applications deja
         * installees continuent de passer dessus.
         */
        $courseType = ($details['course_type_id'] ?? null)
            ? CourseType::query()->find($details['course_type_id'])
            : null;

        /*
         * Mais quand le vehicule en propose, le type n'est plus facultatif.
         *
         * C'est lui qui porte le tarif applique, la duree minimale et parfois
         * le mode : reserver sans l'avoir choisi, c'est etre facture sur le
         * tarif de repli du vehicule pour une course qui n'en releve pas.
         *
         * La meme condition ecarte le type qui existe mais que CE vehicule ne
         * propose pas : sans elle, envoyer le code d'un autre type suffirait a
         * esquiver la duree minimale ou le mode imposes par le vrai. Aucun des
         * deux ecrans ne peut l'envoyer — ils n'affichent que cette liste-la.
         *
         * La liste est celle que le catalogue a montree — `typesProposes()`,
         * la meme methode des deux cotes — sinon un type desactive reclamerait
         * un choix que l'ecran ne propose plus.
         */
        $typesProposes = $vehicle->typesProposes();

        if ($typesProposes->isNotEmpty() && ! $typesProposes->contains('id', $courseType?->id)) {
            throw new BookingRefusee(BookingRefusee::TYPE_REQUIS);
        }

        if ($courseType?->forced_mode && $courseType->forced_mode !== $mode) {
            /*
             * On REFUSE plutot que de basculer en silence : le client a choisi
             * un mode et un prix, et un autre prix n'est pas le sien.
             */
            $libelle = $courseType->forced_mode === BookingPricing::MODE_DAILY ? 'à la journée' : "à l'heure";

            throw new BookingRefusee(
                BookingRefusee::MODE_IMPOSE,
                "Une course « {$courseType->title} » se réserve {$libelle}.",
            );
        }

        if ($courseType?->minimum_hours
            && $this->pricing->billableHours($startsAt, $endsAt) < $courseType->minimum_hours) {
            throw new BookingRefusee(
                BookingRefusee::DUREE_MINIMALE_TYPE,
                "Une course « {$courseType->title} » se réserve pour {$courseType->minimum_hours} heures au minimum.",
            );
        }

        /*
         * La disponibilite est verifiee des la creation, meme si elle sera
         * revue au paiement. Laisser reserver un creneau visiblement pris
         * ferait payer un client pour rien, et la deception arriverait apres
         * le debit.
         */
        $conflit = $this->availability->conflictsFor($vehicle, $startsAt, $endsAt)->first();

        if ($conflit) {
            /*
             * Deux refus differents, parce que le client n'a pas la meme
             * chose a faire. Un creneau vendu ne se liberera pas ; un creneau
             * qu'un autre est en train de payer peut revenir dans le quart
             * d'heure, et le lui dire lui evite d'abandonner.
             */
            throw new BookingRefusee($conflit->status === Booking::STATUS_PENDING_PAYMENT
                ? BookingRefusee::PAIEMENT_EN_COURS
                : BookingRefusee::VEHICULE_INDISPONIBLE);
        }

        try {
            /*
             * Un vehicule sans tarif journalier est refuse a la journee plutot
             * que chiffre a l'heure en douce : ici le client paie, et un prix
             * qui n'est pas celui qu'il a choisi n'est pas un prix.
             */
            if ($mode === BookingPricing::MODE_DAILY && ! $this->pricing->estProposeALaJournee($vehicle)) {
                throw new BookingRefusee(BookingRefusee::MODE_INDISPONIBLE);
            }

            /*
             * Les bornes de duree sont commerciales et viennent des reglages.
             * Une course de 24 heures demandee a l'heure est refusee, pas
             * rebasculee en journee en douce : le client a choisi un mode et
             * un prix, et un autre prix n'est pas le sien.
             */
            if (! $this->pricing->dureeDansLesBornes($mode, $startsAt, $endsAt)) {
                throw new BookingRefusee(
                    BookingRefusee::DUREE_HORS_BORNES,
                    $this->pricing->messageHorsBornes($mode),
                );
            }

            /*
             * Ni dans le passe, ni dans l'instant.
             *
             * Le controle est ici, dans le chemin de l'API client, et pas
             * dans le crochet du modele : un administrateur doit pouvoir
             * enregistrer une course prise au comptoir qui part dans dix
             * minutes. Les bornes de duree, elles, protegent un prix et
             * valent pour tout le monde ; celle-ci protege une organisation,
             * et le comptoir sait s'organiser.
             */
            if (! $this->pricing->respecteLeDelaiDePrevenance($startsAt)) {
                throw new BookingRefusee(
                    BookingRefusee::DELAI_PREVENANCE,
                    $this->pricing->messageDelaiDePrevenance(),
                );
            }

            $quote = $this->pricing->quote(
                $vehicle,
                $startsAt,
                $endsAt,
                $mode,
                $courseType,
                (bool) ($details['parking'] ?? false),
            );
        } catch (InvalidArgumentException) {
            throw new BookingRefusee(BookingRefusee::CRENEAU_INVALIDE);
        }

        return Booking::query()->create(array_merge([
            'user_id' => $user->id,
            'vehicle_id' => $vehicle->id,
            // Le conducteur habituel du vehicule, quand il y en a un. Il reste
            // modifiable depuis l'administration.
            'chauffeur_id' => $vehicle->default_chauffeur_id,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => Booking::STATUS_PENDING_PAYMENT,
        ], $details, $quote->toBookingAttributes()));
    }

    /**
     * Marque expirees les reservations non reglees du client dont le delai est
     * passe.
     *
     * L'ecriture passe par le modele et non par un `update()` en masse : le
     * crochet `saving` tient la coherence entre statut et dates, et une mise a
     * jour globale la contournerait.
     */
    private function expirerLesReservationsPerimees(User $user): void
    {
        Booking::query()
            ->where('user_id', $user->id)
            ->expirable()
            ->get()
            ->each(fn (Booking $booking) => $booking->expirerSiNecessaire());
    }
}
