<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Town;
use Database\Seeders\KinshasaTownsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Les vingt-quatre communes de Kinshasa.
 *
 * `script-run.sh` lance `db:seed --force` en production a chaque
 * deploiement : ce seeder tournera pour de vrai, plusieurs fois. Deux
 * passages doivent laisser exactement le meme etat.
 */
class CommunesKinshasaSeederTest extends TestCase
{
    use RefreshDatabase;

    private function semer(): void
    {
        $this->seed(KinshasaTownsSeeder::class);
    }

    public function test_les_vingt_quatre_communes_sont_presentes(): void
    {
        $this->semer();

        $this->assertSame(24, Town::query()->count());
        $this->assertSame(0, Town::query()->whereNull('latitude')->count());
    }

    public function test_un_second_passage_ne_cree_rien(): void
    {
        $this->semer();
        $avant = Town::query()->count();
        $empreinte = Town::query()->orderBy('id')->get(['id', 'title', 'slug', 'latitude', 'longitude'])->toArray();

        $this->semer();

        $this->assertSame($avant, Town::query()->count());
        $this->assertSame(
            $empreinte,
            Town::query()->orderBy('id')->get(['id', 'title', 'slug', 'latitude', 'longitude'])->toArray(),
        );
    }

    /**
     * Une commune mal orthographiee recoit ses coordonnees, pas une jumelle.
     *
     * « Kitambo » est l'orthographe en base, Kintambo l'officielle. Un
     * rapprochement par le nom aurait cree une seconde ligne, alors que la
     * premiere est deja referencee par des adresses et des commandes.
     */
    public function test_une_commune_existante_garde_son_slug_et_son_titre(): void
    {
        $commune = Town::query()->create([
            'title' => 'Kitambo',
            'slug' => 'kitambo',
            'is_active' => true,
        ]);

        $this->semer();

        $commune->refresh();

        $this->assertSame('Kitambo', $commune->title);
        $this->assertSame('kitambo', $commune->slug);
        $this->assertSame('-4.343433', $commune->latitude);
        $this->assertSame('15.266748', $commune->longitude);

        // Une seule Kintambo en base, sous l'un ou l'autre nom.
        $this->assertSame(0, Town::query()->where('slug', 'kintambo')->count());
        $this->assertSame(24, Town::query()->count());
    }

    public function test_une_commune_deja_ouverte_a_la_location_le_reste(): void
    {
        $commune = Town::query()->create([
            'title' => 'Gombe',
            'slug' => 'gombe',
            'is_active' => true,
            'rental_available' => true,
        ]);

        $this->semer();

        $this->assertTrue($commune->refresh()->rental_available);
        $this->assertTrue($commune->is_active);
    }

    /** Une commune creee par le seeder nait fermee : elle n'a pas de tarif. */
    public function test_une_commune_creee_nait_fermee(): void
    {
        $this->semer();

        $bumbu = Town::query()->where('slug', 'bumbu')->first();

        $this->assertFalse($bumbu->rental_available);
        $this->assertFalse($bumbu->is_active);
    }

    public function test_la_ville_de_rattachement_est_renseignee_quand_elle_manque(): void
    {
        $kinshasa = City::query()->create([
            'title' => 'Kinshasa', 'slug' => 'kinshasa', 'is_active' => true,
        ]);

        $orpheline = Town::query()->create([
            'title' => 'Limete', 'slug' => 'limete', 'is_active' => true,
        ]);

        $this->semer();

        $this->assertSame($kinshasa->id, $orpheline->refresh()->city_id);
    }
}
