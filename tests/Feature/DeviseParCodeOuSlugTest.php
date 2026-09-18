<?php

namespace Tests\Feature;

use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Une devise se retrouve par le nom qu'on a montre au client.
 *
 * L'API cherchait par slug (« dollars ») alors qu'elle n'affiche que le code
 * (« 13 USD »). Un assistant qui lit un prix puis renvoie « USD » pour
 * demander des suggestions se faisait repondre « Cette devise est
 * introuvable » — un refus incomprehensible, puisque la valeur venait de
 * l'API elle-meme.
 */
class DeviseParCodeOuSlugTest extends TestCase
{
    use RefreshDatabase;

    private function dollars(): Currency
    {
        return Currency::query()->create([
            'title' => 'Dollars',
            'code' => 'USD',
            'is_active' => 1,
        ]);
    }

    public function test_elle_se_trouve_par_son_slug(): void
    {
        $devise = $this->dollars();

        $this->assertSame($devise->id, Currency::parSlugOuCode('dollars')?->id);
    }

    public function test_elle_se_trouve_par_son_code(): void
    {
        $devise = $this->dollars();

        // C'est ce que l'API affiche, donc ce qu'un client renverra.
        $this->assertSame($devise->id, Currency::parSlugOuCode('USD')?->id);
    }

    public function test_la_casse_est_ignoree(): void
    {
        $devise = $this->dollars();

        $this->assertSame($devise->id, Currency::parSlugOuCode('usd')?->id);
        $this->assertSame($devise->id, Currency::parSlugOuCode('Dollars')?->id);
    }

    public function test_une_devise_inconnue_reste_introuvable(): void
    {
        $this->dollars();

        $this->assertNull(Currency::parSlugOuCode('euros'));
        $this->assertNull(Currency::parSlugOuCode(null));
    }
}
