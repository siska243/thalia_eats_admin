<?php

use App\Models\Booking;
use App\Models\Chauffeur;
use App\Models\Currency;
use App\Models\PaimentMethod;
use App\Models\StatusPayement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les paiements d'une reservation.
     *
     * Table distincte de `payements`, qui porte `commande_id` en NOT NULL et
     * sert les repas. L'etendre demanderait de toucher une table de production
     * pour un module neuf ; on prefere une table propre qui pointe vers les
     * MEMES tables de reference — `paiment_methods` et `status_payements` —
     * pour que les moyens de paiement et les statuts restent definis une seule
     * fois pour les deux metiers.
     *
     * Une ligne par TENTATIVE, pas par reservation : un client qui se trompe de
     * numero, reessaie et reussit en laisse trois. Un champ unique sur la
     * reservation n'en garderait qu'une, et ferait disparaitre les echecs —
     * precisement ce qu'on veut voir quand un paiement est conteste.
     */
    public function up(): void
    {
        Schema::create('booking_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Booking::class, 'booking_id');

            $table->foreignIdFor(PaimentMethod::class, 'paiment_method_id')->nullable();
            $table->foreignIdFor(StatusPayement::class, 'status_payement_id')->nullable();

            // « deposit » : les 10 % en ligne. « balance » : les 90 % en
            // especes, remis au chauffeur.
            $table->string('kind');

            $table->decimal('amount', 10, 2);
            $table->foreignIdFor(Currency::class, 'currency_id');

            // Le canal renvoye par le prestataire : mpesa, orange, airtel,
            // card... On le garde tel quel, sans le normaliser : c'est sa
            // valeur qui permettra un rapprochement.
            $table->string('channel')->nullable();

            /*
             * Deux references, et elles ne se confondent pas : `reference` est
             * celle que nous emettons, `provider_reference` celle que FlexPay
             * confirme. Les melanger rend un rapprochement bancaire
             * impossible. C'est deja la structure de la table `payements`.
             */
            $table->string('reference')->nullable();
            $table->string('provider_reference')->nullable();

            $table->string('phone')->nullable();

            // Renseigne pour les especes : le chauffeur qui declare avoir
            // encaisse. Nul pour un paiement en ligne.
            $table->foreignIdFor(Chauffeur::class, 'recorded_by')->nullable();

            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('failure_reason')->nullable();

            $table->timestamps();

            $table->index(['booking_id', 'kind']);
            $table->index('provider_reference');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_payments');
    }
};
