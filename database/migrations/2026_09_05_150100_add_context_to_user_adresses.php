<?php

use App\Models\Town;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La table user_adresses ne portait ni commune, ni rue, ni libelle : elle ne
 * pouvait donc pas restituer une adresse de livraison complete, alors que la
 * commande en exige une. Ces colonnes la rendent reutilisable telle quelle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_adresses', function (Blueprint $table) {
            $table->foreignIdFor(Town::class, 'town_id')->nullable()->after('user_id');
            $table->string('street')->nullable()->after('adresse');
            $table->string('number_street')->nullable()->after('street');
            // « Maison », « Bureau »… choisi par le client.
            $table->string('label')->nullable()->after('reference');
            $table->string('reference')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('user_adresses', function (Blueprint $table) {
            $table->dropColumn(['town_id', 'street', 'number_street', 'label']);
        });
    }
};
