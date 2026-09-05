<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * L'adresse du webhook FlexPay etait ecrite en dur a deux endroits du
 * CommandeController, avec deux valeurs differentes : valide() imposait
 * app.thaliaeats.com, paiement() reprenait celle envoyee par le client.
 *
 * Un paiement pouvait donc etre confirme sur une instance qui ne detenait pas
 * la commande.
 */
class FlexpayCallbackConfigTest extends TestCase
{
    public function test_l_adresse_par_defaut_suit_l_instance_courante(): void
    {
        $expected = rtrim(config('app.url'), '/').'/api/webhook-paiement-flexpay';

        $this->assertSame($expected, config('flexpay.callback_url'));
    }

    public function test_le_controleur_ne_contient_plus_d_adresse_en_dur(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/CommandeController.php'));

        $this->assertStringNotContainsString('webhook-paiement-flexpay', $source);
        $this->assertSame(2, substr_count($source, "config('flexpay.callback_url')"));
    }

    public function test_le_client_ne_choisit_plus_l_adresse_de_confirmation(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Api/CommandeController.php'));

        $this->assertStringNotContainsString("\$request->input('callback_url')", $source);
    }
}
