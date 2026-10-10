<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les codes d'autorisation, à usage unique et de très courte durée.
 *
 * Le code n'est jamais stocké en clair : une fuite de cette table ne doit pas
 * permettre d'échanger un code contre un jeton. Comme le code fait 32 octets
 * aléatoires, un SHA-256 suffit — il n'y a rien à deviner par force brute,
 * contrairement à un mot de passe.
 *
 * Rien ne se supprime : un code consommé porte sa date de consommation et
 * reste en base. C'est ce qui permet, après coup, de savoir qu'un code a servi
 * deux fois plutôt que de ne rien pouvoir dire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_authorization_codes', function (Blueprint $table) {
            $table->id();

            $table->string('code_hash', 64)->unique();

            $table->foreignId('oauth_client_id')->constrained('oauth_clients');
            $table->foreignId('user_id')->constrained('users');

            // Le code est lié à TOUT ce qui a servi à l'obtenir : la
            // redirection présentée à l'échange doit être identique, sinon un
            // code intercepté serait rejouable vers une autre destination.
            $table->string('redirect_uri', 2048);

            $table->string('code_challenge', 128);
            $table->string('code_challenge_method', 10);

            $table->json('scopes');

            // RFC 8707 : la ressource pour laquelle le jeton est demandé. Le
            // connecteur MCP l'envoie ; on la revérifie à l'échange.
            $table->string('resource', 2048)->nullable();

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_authorization_codes');
    }
};
