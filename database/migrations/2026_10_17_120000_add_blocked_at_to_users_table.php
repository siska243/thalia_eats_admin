<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bloquer un compte depuis l'administration.
 *
 * Une colonne NEUVE, et non `is_active`, qui existe deja : elle vaut faux
 * pour la quasi-totalite des comptes et n'est lue nulle part. La detourner en
 * drapeau de blocage aurait mis presque tous les clients dehors au premier
 * deploiement.
 *
 * Nullable et nulle par defaut : personne n'est bloque tant que personne ne
 * l'a decide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('blocked_at')->nullable()->after('is_active');
            $table->string('blocked_reason')->nullable()->after('blocked_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['blocked_at', 'blocked_reason']);
        });
    }
};
