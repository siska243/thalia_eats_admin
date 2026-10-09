<?php

namespace Tests\Feature\Paiement;

use App\Models\ConfigurationPayement;
use App\Models\User;
use App\Wrappers\FlexPay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * FlexPay refuse un numero qui porte un « + » ou des espaces : il repond
 * « la taille de votre numero de telephone ne correspond pas ».
 *
 * Trois appelants existaient et un seul nettoyait le numero. Le client
 * voyait donc le refus en reservant une voiture, mais pas en commandant un
 * repas, pour exactement le meme numero.
 */
class NumeroFlexPayTest extends TestCase
{
    use RefreshDatabase;

    private function charge(): array
    {
        return [
            'amount' => 10.0,
            'currency' => 'USD',
            'reference' => 'R-TEST',
            'callback_url' => 'https://exemple.test/retour',
            'approve_url' => 'https://exemple.test/ok',
            'cancel_url' => 'https://exemple.test/annule',
            'decline_url' => 'https://exemple.test/refus',
            'name' => 'Client',
            'email' => 'client@exemple.test',
        ];
    }

    private function numeroEnvoye(?string $phone): ?string
    {
        ConfigurationPayement::query()->create([
            'token' => 'jeton-essai',
            'token_key' => 'cle-essai',
            'active' => 1,
            'user_id' => User::factory()->create()->id,
        ]);

        Http::fake(['*' => Http::response(['code' => '0'], 200)]);

        FlexPay::sendData([...$this->charge(), 'phone' => $phone]);

        $envoye = null;

        Http::assertSent(function ($request) use (&$envoye) {
            $envoye = $request->data()['phone'] ?? null;

            return true;
        });

        return $envoye;
    }

    #[Test]
    public function le_plus_et_les_espaces_ne_partent_pas_chez_flexpay(): void
    {
        $this->assertSame('243810000002', $this->numeroEnvoye('+243 81 000 00 02'));
    }

    #[Test]
    public function un_numero_deja_propre_traverse_sans_changer(): void
    {
        $this->assertSame('243810000002', $this->numeroEnvoye('243810000002'));
    }

    #[Test]
    public function un_numero_absent_ne_fait_pas_tomber_l_envoi(): void
    {
        $this->assertSame('', $this->numeroEnvoye(null));
    }
}
