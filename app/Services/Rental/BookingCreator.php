<?php

namespace App\Services\Rental;

use App\Exceptions\BookingRefusee;
use App\Models\Booking;
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
 * La reservation nait en attente de paiement et ne retient rien. Le premier
 * qui paie emporte le vehicule ; c'est `BookingConfirmation` qui tranche, sous
 * verrou, au moment ou l'acompte arrive.
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
         * La disponibilite est verifiee des la creation, meme si elle sera
         * revue au paiement. Laisser reserver un creneau visiblement pris
         * ferait payer un client pour rien, et la deception arriverait apres
         * le debit.
         */
        if (! $this->availability->isVehicleAvailable($vehicle, $startsAt, $endsAt)) {
            throw new BookingRefusee(BookingRefusee::VEHICULE_INDISPONIBLE);
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

            $quote = $this->pricing->quote($vehicle, $startsAt, $endsAt, $mode);
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
}
