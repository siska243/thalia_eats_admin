<?php

namespace App\Wrappers;

use App\Models\ConfigurationPayement;
use Exception;
use Illuminate\Support\Facades\Http;

class FlexPay
{
    const url = 'https://backend.flexpay.cd/api/rest/v1';

    const urlCart = 'https://cardpayment.flexpay.cd/v1.1/pay';

    /**
     * Le numero tel que FlexPay l'attend : des chiffres, et rien d'autre.
     *
     * « +243 81 000 00 02 » lui fait repondre « la taille de votre numero de
     * telephone ne correspond pas ». Trois endroits appelaient `sendData` et
     * un seul connaissait la regle : la commande de repas nettoyait le
     * numero, la location et la precommande le passaient tel quel. Le client
     * voyait donc le refus sur un chemin et pas sur l'autre, pour le meme
     * numero.
     *
     * Le nettoyage vit ici, au seul endroit qui parle a FlexPay, pour qu'un
     * quatrieme appelant ne puisse pas l'oublier a son tour. Appliquer deux
     * fois ne change rien : les appelants qui nettoyaient deja restent justes.
     */
    private static function numeroPourFlexPay(?string $phone): string
    {
        return preg_replace('/\D/', '', (string) $phone);
    }

    public static function sendData(array $data, string $method = 'mobile')
    {

        $formData = [
            'merchant' => self::getConfig()?->token_key,
            'type' => '1',
            'amount' => $data['amount'],
            'phone' => self::numeroPourFlexPay($data['phone'] ?? null),
            'currency' => $data['currency'],
            'reference' => $data['reference'],
            'callbackUrl' => $data['callback_url'],
            'callback_url' => $data['callback_url'],
            'approve_url' => $data['approve_url'],
            'cancel_url' => $data['cancel_url'],
            'decline_url' => $data['decline_url'],
            'language' => 'fr',
            'description' => ! empty($data['description']) ? $data['description'] : '',
            'name' => $data['name'],
            'email' => $data['email'],
        ];

        if ($method != 'mobile') {
            $token = self::getConfig()?->token;
            $formData['authorization'] = "Bearer {$token}";
        }

        $response = Http::withToken(self::getConfig()?->token)
            ->timeout(120)
            ->post($method == 'mobile' ? self::url.'/paymentService' : self::urlCart, $formData);

        if ($response->successful()) {
            return $response->json();
        }

        return $response->json();

    }

    public static function checkPaiement(?string $orderNumber)
    {
        try {
            $url = self::url.'/check/'.$orderNumber;
            $response = Http::withToken(self::getConfig()?->token)
                ->timeout(120)
                ->get($url);

            if ($response->successful()) {

                return $response->json();
            }

            return $response->json();
        } catch (Exception $exception) {
            return $exception;
        }
    }

    public static function getConfig()
    {
        return ConfigurationPayement::query()->where('active', true)->first();
    }
}
