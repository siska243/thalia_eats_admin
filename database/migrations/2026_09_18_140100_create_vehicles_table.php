<?php

use App\Models\Currency;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique();
            $table->string('slug')->unique();

            $table->string('brand');
            $table->string('model');
            $table->string('plate_number')->unique();
            $table->unsignedSmallInteger('seats')->default(4);
            $table->text('description')->nullable();
            $table->string('image')->nullable();

            /*
             * Le montant est en decimal, pas en float.
             *
             * Les tables existantes stockent l'argent en float : sur une
             * commande de repas l'ecart d'arrondi reste invisible, sur une
             * facturation a l'heure il s'accumule. Une table neuve n'a pas de
             * raison de repeter ce defaut.
             */
            $table->decimal('hourly_rate', 10, 2);
            $table->foreignIdFor(Currency::class, 'currency_id');

            $table->boolean('is_active')->default(true);

            $table->timestamps();

            /*
             * Effacement doux : les reservations passees referencent le
             * vehicule, leur historique doit rester lisible apres son retrait
             * du parc.
             */
            $table->softDeletes();

            $table->index(['is_active', 'hourly_rate']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
