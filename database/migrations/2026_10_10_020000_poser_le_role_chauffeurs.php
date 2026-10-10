<?php

use App\Models\Chauffeur;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

/**
 * Le role `chauffeurs`, et les fiches deja en base qui ne l'ont pas.
 *
 * L'application route sur les roles rendus par la connexion. Sans ce role,
 * un chauffeur deja enregistre ouvrait l'application cliente : son compte
 * n'annoncait rien qui le distingue.
 *
 * Idempotente : `firstOrCreate` sur le role, `assignRole` ne double pas une
 * attribution existante. `script-run.sh` rejoue les migrations en production.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Garde `web`, comme `drivers`, `client` et `restaurant`.
         *
         * Le depot annonce le garde `api` pour les permissions, mais les
         * roles ecrits en base portent tous `web` et c'est avec eux que
         * `assignRole()` travaille depuis l'origine. En poser un sur un autre
         * garde ici aurait donne un role que personne ne peut attribuer.
         */
        Role::query()->firstOrCreate(['name' => 'chauffeurs', 'guard_name' => 'web']);

        Chauffeur::query()->with('user')->get()->each(
            fn (Chauffeur $chauffeur) => $chauffeur->user?->assignRole('chauffeurs')
        );
    }

    public function down(): void
    {
        Chauffeur::query()->with('user')->get()->each(
            fn (Chauffeur $chauffeur) => $chauffeur->user?->removeRole('chauffeurs')
        );
    }
};
