<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le moment ou un client a engage le paiement de son acompte.
 *
 * Jusqu'ici une reservation non reglee ne retenait rien : deux clients
 * pouvaient payer le meme vehicule sur le meme creneau, et le perdant etait
 * rembourse apres coup. La colonne marque celui qui est parti payer, et
 * `Booking::scopeHolding()` lui laisse le creneau le temps de son echeance.
 *
 * Nullable, sans valeur par defaut : les reservations ecrites avant cette
 * regle ne retiennent rien, exactement comme avant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dateTime('deposit_started_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropColumn('deposit_started_at');
        });
    }
};
