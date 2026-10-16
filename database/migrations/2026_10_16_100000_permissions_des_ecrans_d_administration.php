<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Les permissions des ecrans d'administration, generees et rattachees.
 *
 * Un ecran Filament dont la permission n'existe pas est INVISIBLE, meme pour
 * un super_admin : `config/filament-shield.php` pose `define_via_gate` a
 * `false`, et le role ne contourne donc rien. Verifie en base le 16 octobre —
 * `can('View:ManageFoodSettings')` rendait faux sur une page pourtant livree.
 *
 * Or `shield:generate` n'etait joue nulle part : ni dans `script-run.sh`, ni
 * dans un seeder, ni dans `deploy/`. Chaque ecran nouveau restait donc muet
 * jusqu'a ce que quelqu'un y pense. C'est ce qui avait vide le menu Location.
 *
 * Pourquoi une MIGRATION et pas un seeder : le deploiement Docker ne lance
 * jamais `db:seed` — `deploy/deploy.sh` et `deploy/entrypoint.sh` le disent
 * explicitement — alors qu'il joue `migrate --force`. Une migration
 * idempotente est le seul vehicule deja employe ici pour poser du role ou de
 * la permission au deploiement ; le precedent est
 * `2026_10_10_020000_poser_le_role_chauffeurs`.
 *
 * Idempotente : Shield ne recree pas ce qui existe, et `givePermissionTo`
 * ne double pas un rattachement.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * `--option=permissions` est indispensable.
         *
         * Sans lui, Shield ecrirait aussi des fichiers de policy sur le
         * disque. Un conteneur de production n'a pas a voir son code change
         * par une migration.
         */
        Artisan::call('shield:generate', [
            '--all' => true,
            '--panel' => 'admin',
            '--option' => 'permissions',
            '--no-interaction' => true,
        ]);

        $role = Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        /*
         * Tout ce qui existe, sans exception.
         *
         * C'est la definition meme de ce role, et le seul moyen de garantir
         * qu'un ecran ajoute demain sera visible le jour de sa mise en ligne
         * plutot qu'apres un appel telephonique.
         */
        $manquantes = Permission::query()
            ->where('guard_name', $role->guard_name)
            ->whereNotIn('id', $role->permissions()->pluck('id'))
            ->get();

        if ($manquantes->isNotEmpty()) {
            $role->givePermissionTo($manquantes);
        }

        app()['cache']->forget('spatie.permission.cache');
    }

    public function down(): void
    {
        /*
         * Rien.
         *
         * Retirer des permissions a l'administrateur casserait son acces sans
         * rien reparer. Une migration qui rend un panneau inutilisable n'est
         * pas reversible, elle est dangereuse.
         */
    }
};
