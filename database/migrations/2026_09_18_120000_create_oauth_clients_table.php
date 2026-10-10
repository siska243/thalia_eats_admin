<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Les clients OAuth enregistrés dynamiquement (RFC 7591).
 *
 * Un client, ici, c'est un logiciel assistant (Claude, ChatGPT) et non un
 * utilisateur : il s'enregistre tout seul, sans que personne ne saisisse
 * d'identifiant. Il n'y a donc pas de secret à stocker — ce sont des clients
 * publics, et c'est PKCE qui les lie à leur propre demande d'autorisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->id();

            // L'identifiant public annoncé au client. Aléatoire et non
            // devinable : il apparaît dans les URL d'autorisation.
            $table->string('client_id', 64)->unique();

            // Ce que le client dit de lui-même. Affiché au client final sur la
            // page de consentement, donc à échapper à l'affichage.
            $table->string('client_name');

            // Liste blanche des redirections. Toute comparaison à l'échange se
            // fait sur une correspondance exacte avec l'une de ces valeurs.
            $table->json('redirect_uris');

            $table->json('grant_types');
            $table->json('response_types');
            $table->string('token_endpoint_auth_method', 40);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('oauth_clients');
    }
};
