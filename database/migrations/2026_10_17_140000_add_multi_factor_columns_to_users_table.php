<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La double authentification, au choix de chacun.
 *
 * Trois colonnes, parce que Filament sait deja faire les deux facteurs et
 * attend ces champs : le secret TOTP d'une application d'authentification,
 * ses codes de secours, et l'interrupteur du facteur par courriel.
 *
 * TOUTES NULLABLES, et l'interrupteur a faux : personne n'est soumis a quoi
 * que ce soit tant qu'il ne l'a pas demande. Le proprietaire : « c'est a
 * l'utilisateur de choisir ».
 *
 * Le secret et les codes de secours sont chiffres par le modele, pas ici :
 * une fuite de la base ne doit pas livrer le second facteur avec le premier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('app_authentication_secret')->nullable()->after('password');
            $table->text('app_authentication_recovery_codes')->nullable()->after('app_authentication_secret');
            $table->boolean('has_email_authentication')->default(false)->after('app_authentication_recovery_codes');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'app_authentication_secret',
                'app_authentication_recovery_codes',
                'has_email_authentication',
            ]);
        });
    }
};
