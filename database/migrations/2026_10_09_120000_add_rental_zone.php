<?php

use App\Models\Town;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La location ne dessert pas toutes les communes.
 *
 * Le devis dit « uniquement dans la ville de Kinshasa », mais la liste des
 * communes couvertes bougera avant la prochaine livraison : elle vit donc en
 * base, commune par commune, au lieu d'etre une constante dans le code.
 *
 * Les deux colonnes arrivent ensemble parce qu'elles n'ont de sens que l'une
 * avec l'autre : marquer une commune desservie ne sert a rien tant qu'une
 * reservation ne dit pas d'ou elle part.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('towns', function (Blueprint $table) {
            /*
             * Faux par defaut : ouvrir une commune est une decision
             * commerciale. Le contraire aurait ouvert tout le pays le jour du
             * deploiement, sans chauffeur pour y aller.
             */
            $table->boolean('rental_available')->default(false)->after('is_active');
        });

        Schema::table('bookings', function (Blueprint $table) {
            /*
             * Nullable : les applications deja installees n'envoient pas de
             * commune. Les refuser casserait le parc existant pour un controle
             * qu'elles ne savent pas encore satisfaire.
             *
             * nullOnDelete : supprimer une commune ne doit pas emporter
             * l'historique des courses qui en sont parties.
             */
            $table->foreignIdFor(Town::class, 'pickup_town_id')
                ->nullable()
                ->after('pickup_location')
                ->constrained('towns')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pickup_town_id');
        });

        Schema::table('towns', fn (Blueprint $table) => $table->dropColumn('rental_available'));
    }
};
