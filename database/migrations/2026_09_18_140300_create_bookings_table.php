<?php

use App\Models\Chauffeur;
use App\Models\Currency;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();

            $table->foreignIdFor(User::class, 'user_id');
            $table->foreignIdFor(Vehicle::class, 'vehicle_id');

            /*
             * Le chauffeur est porte par la reservation, pas par le vehicule.
             *
             * Un chauffeur conduit plusieurs vehicules, et un vehicule change
             * de chauffeur selon les jours. Le rattacher au vehicule rendrait
             * impossible de representer deux creneaux du meme vehicule confies
             * a deux personnes.
             *
             * Nullable : l'affectation se fait a l'administration, apres que le
             * client a paye son acompte.
             */
            $table->foreignIdFor(Chauffeur::class, 'chauffeur_id')->nullable();

            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            /*
             * Au-dela de cette date, une reservation jamais payee est nettoyee.
             * Elle ne bloquait rien entre-temps : le premier qui paie emporte
             * le vehicule.
             */
            $table->dateTime('expires_at')->nullable();

            $table->string('pickup_location');
            $table->decimal('pickup_lat', 10, 7)->nullable();
            $table->decimal('pickup_lng', 10, 7)->nullable();

            // Facultative : le devis dit « s'il souhaite la preciser ».
            $table->string('dropoff_location')->nullable();
            $table->decimal('dropoff_lat', 10, 7)->nullable();
            $table->decimal('dropoff_lng', 10, 7)->nullable();

            /*
             * Le tarif est recopie sur la reservation.
             *
             * Sans cette copie, changer le prix d'un vehicule reecrirait le
             * montant des reservations deja payees. Une reservation porte le
             * prix qui avait cours au moment ou elle a ete acceptee.
             */
            $table->decimal('hourly_rate', 10, 2);
            $table->decimal('duration_hours', 6, 2);
            $table->decimal('total', 10, 2);
            $table->decimal('deposit', 10, 2);
            $table->decimal('balance', 10, 2);
            $table->foreignIdFor(Currency::class, 'currency_id');

            $table->string('status')->default('pending_payment');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->timestamp('completed_at')->nullable();

            /*
             * Le remboursement du : calcule a l'annulation ou a la perte, mais
             * jamais execute en V1. Le mouvement d'argent reste manuel, et
             * `refunded_at` en garde la trace quand il a lieu.
             */
            $table->decimal('refund_amount', 10, 2)->nullable();
            $table->timestamp('refunded_at')->nullable();

            /*
             * La preuve de prise en charge.
             *
             * Le code est montre au CLIENT, en chiffres et en QR. Le chauffeur
             * ne le recoit jamais dans ses donnees : il le saisit, le serveur
             * compare. Sans cette regle, un chauffeur pourrait declarer une
             * course qu'il n'a pas faite — c'est exactement la faille corrigee
             * sur les livraisons de repas.
             */
            $table->string('pickup_code', 8)->nullable();
            $table->unsignedTinyInteger('pickup_code_attempts')->default(0);

            // Blocage leve par l'administration : pas de deverrouillage
            // automatique au bout d'un delai, contrairement aux livreurs.
            $table->timestamp('pickup_code_locked_at')->nullable();

            $table->timestamp('picked_up_at')->nullable();
            $table->foreignIdFor(Chauffeur::class, 'picked_up_by')->nullable();

            // Le solde est encaisse en especes par le chauffeur : on enregistre
            // qui et quand, sinon l'argent remis n'a aucune trace.
            $table->timestamp('balance_collected_at')->nullable();
            $table->foreignIdFor(Chauffeur::class, 'balance_collected_by')->nullable();

            $table->timestamps();

            /*
             * Cet index sert la seule requete qui compte : « ce vehicule est-il
             * libre entre telle et telle heure », jouee a chaque consultation
             * du catalogue et a chaque paiement.
             */
            $table->index(['vehicle_id', 'starts_at', 'ends_at']);
            $table->index(['chauffeur_id', 'starts_at', 'ends_at']);
            $table->index(['status', 'starts_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
