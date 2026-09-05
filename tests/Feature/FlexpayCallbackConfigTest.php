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
 *
 * L'adresse par defaut ne derive plus d'APP_URL : dans ce projet, APP_URL
 * n'est pas maintenue comme l'origine publique (en local, elle pointe vers
 * un tunnel ngrok jetable). Le repli par defaut est donc le domaine de
 * production, deliberement : c'est la direction sure pour une confirmation
 * de paiement.
 */
class FlexpayCallbackConfigTest extends TestCase
{
    public function test_l_adresse_par_defaut_est_celle_de_production(): void
    {
        $this->assertSame(
            'https://app.thaliaeats.com/api/webhook-paiement-flexpay',
            config('flexpay.callback_url')
        );
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
