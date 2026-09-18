<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Les chauffeurs de location.
     *
     * Table distincte de `delivrery_drivers` : un livreur a moto et un
     * chauffeur de voiture ne font pas le meme metier, n'ont pas le meme
     * permis et ne voient pas les memes ecrans. Les reunir remplirait
     * l'application livreur de reservations qui ne la concernent pas. Une
     * personne exercant les deux existe des deux cotes, reliee au meme `User`.
     */
    public function up(): void
    {
        Schema::create('chauffeurs', function (Blueprint $table) {
            $table->id();

            // Le chauffeur a un compte : il consulte ses courses et son
            // historique. Le lien n'est donc pas optionnel.
            $table->foreignIdFor(User::class, 'user_id')->unique();

            $table->string('full_name');
            $table->string('phone');
            $table->string('licence_number')->nullable();
            $table->date('licence_expires_at')->nullable();

            $table->boolean('is_active')->default(true);

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chauffeurs');
    }
};
