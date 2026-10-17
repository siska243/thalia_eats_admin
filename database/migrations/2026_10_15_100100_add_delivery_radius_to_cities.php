<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le rayon de livraison propre a une ville.
 *
 * Nullable, et c'est le coeur du reglage : `null` veut dire « cette ville n'a
 * pas d'avis, applique le rayon global ». Une valeur par defaut aurait fige 30
 * dans chaque ligne et rendu impossible de changer le global apres coup.
 *
 * Kinshasa s'etale sur une quarantaine de kilometres, Lubumbashi beaucoup
 * moins : la valeur ne peut pas etre la meme partout, et elle se negocie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cities', function (Blueprint $table) {
            $table->decimal('max_delivery_radius_km', 6, 2)
                ->nullable()
                ->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('cities', fn (Blueprint $table) => $table->dropColumn('max_delivery_radius_km'));
    }
};
