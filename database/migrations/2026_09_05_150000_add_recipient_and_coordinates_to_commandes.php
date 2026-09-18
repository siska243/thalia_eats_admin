<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Une commande ne portait ni coordonnees ni destinataire.
 *
 * - Sans lat/long, une adresse choisie sur une carte n'avait nulle part ou
 *   etre enregistree : le livreur ne recevait qu'un texte libre.
 * - Sans destinataire, il etait impossible de commander pour quelqu'un
 *   d'autre : le livreur appelait le compte, pas la personne a livrer.
 *
 * Toutes les colonnes sont nullables : les commandes existantes restent
 * valides, et l'ancienne application continue de fonctionner sans les
 * renseigner.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->float('lat')->nullable()->after('number_street');
            $table->float('long')->nullable()->after('lat');
            $table->string('recipient_name')->nullable()->after('long');
            $table->string('recipient_phone')->nullable()->after('recipient_name');
        });
    }

    public function down(): void
    {
        Schema::table('commandes', function (Blueprint $table) {
            $table->dropColumn(['lat', 'long', 'recipient_name', 'recipient_phone']);
        });
    }
};
