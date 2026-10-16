<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Rend visible chaque ecran d'administration.
 *
 * Un ecran Filament dont la permission n'existe pas est INVISIBLE, meme pour
 * un super_admin : `config/filament-shield.php` pose `define_via_gate` a
 * `false`, et le role ne contourne donc rien.
 *
 * Rien ne jouait `shield:generate` au deploiement : chaque ecran ajoute
 * restait muet jusqu'a ce que quelqu'un y pense. C'est ce qui avait vide le
 * menu Location.
 *
 * Une migration ne suffisait pas : elle ne tourne qu'une fois, et ne couvre
 * donc que les ecrans existants ce jour-la. Cette commande est rejouee a
 * chaque mise en ligne, a cote de `migrate`.
 *
 * Idempotente : Shield ne recree pas ce qui existe, et on n'attache que ce
 * qui manque.
 */
class SynchroniserPermissionsAdmin extends Command
{
    protected $signature = 'thalia:permissions-admin';

    protected $description = "Genere les permissions des ecrans d'administration et les donne au super_admin";

    public function handle(): int
    {
        /*
         * `--option=permissions` est indispensable : sans lui, Shield
         * ecrirait aussi des fichiers de policy sur le disque. Un conteneur
         * de production n'a pas a voir son code change par un deploiement.
         */
        $this->callSilent('shield:generate', [
            '--all' => true,
            '--panel' => 'admin',
            '--option' => 'permissions',
            '--no-interaction' => true,
        ]);

        $role = Role::query()->firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);

        $manquantes = Permission::query()
            ->where('guard_name', $role->guard_name)
            ->whereNotIn('id', $role->permissions()->pluck('id'))
            ->get();

        if ($manquantes->isNotEmpty()) {
            $role->givePermissionTo($manquantes);
        }

        app()['cache']->forget('spatie.permission.cache');

        $this->info($manquantes->isEmpty()
            ? 'Permissions deja a jour.'
            : $manquantes->count().' permission(s) accordee(s) au super_admin : '.$manquantes->pluck('name')->implode(', '));

        return self::SUCCESS;
    }
}
