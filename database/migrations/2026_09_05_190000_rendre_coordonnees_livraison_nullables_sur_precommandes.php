<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'assistant ne demande plus que la commune : adresse, nom et téléphone du
 * destinataire sont désormais saisis par le client lui-même sur la page du lien
 * de paiement. Une pré-commande naît donc sans coordonnées de livraison, et les
 * trois colonnes doivent l'admettre. Rien n'est supprimé : les pré-commandes
 * déjà créées gardent leurs valeurs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('precommandes', function (Blueprint $table) {
            // ->change() exige de redéclarer TOUS les attributs de la colonne,
            // pas seulement celui qui change : un attribut omis est un attribut
            // perdu.
            $table->string('adresse_delivery')->nullable()->change();
            $table->string('recipient_name')->nullable()->change();
            $table->string('recipient_phone')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('precommandes', function (Blueprint $table) {
            $table->string('adresse_delivery')->nullable(false)->change();
            $table->string('recipient_name')->nullable(false)->change();
            $table->string('recipient_phone')->nullable(false)->change();
        });
    }
};
