<?php

use App\Models\Chauffeur;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Le chauffeur attitre d'un vehicule.
     *
     * Il s'ajoute a l'affectation portee par la reservation, il ne la remplace
     * pas. Chaque voiture a « son » chauffeur au quotidien, mais un vehicule
     * change de conducteur selon les jours, et deux creneaux du meme vehicule
     * peuvent etre confies a deux personnes. Rattacher l'affectation ferme au
     * vehicule rendrait ce cas impossible a representer.
     *
     * Ce champ sert donc de valeur par defaut : il pre-remplit la reservation,
     * et l'affectation reelle reste sur elle.
     *
     * `nullOnDelete` : retirer un chauffeur du parc ne doit pas empecher de
     * supprimer sa fiche, ni faire disparaitre le vehicule avec lui.
     */
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->foreignIdFor(Chauffeur::class, 'default_chauffeur_id')
                ->nullable()
                ->after('currency_id')
                ->constrained('chauffeurs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_chauffeur_id');
        });
    }
};
