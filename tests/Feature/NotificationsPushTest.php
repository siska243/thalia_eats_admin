<?php

namespace Tests\Feature;

use App\Wrappers\FirebasePushNotification;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\MulticastSendReport;
use Mockery;
use Tests\TestCase;

/**
 * Les notifications push survivent à kreait/laravel-firebase 6 -> 7.
 *
 * Rien ne couvrait ce code, et il ne se valide pas en le lisant : une
 * notification qui ne part plus ne se voit pas dans une suite de tests, elle
 * se voit sur le téléphone d'un livreur qui n'a pas su qu'une course
 * l'attendait.
 *
 * Le service `firebase.messaging` est remplacé par un double : on vérifie que
 * le message se construit et que la bonne méthode du SDK est appelée, sans
 * toucher au réseau ni aux identifiants Firebase.
 */
class NotificationsPushTest extends TestCase
{
    public function test_elle_envoie_a_un_seul_appareil(): void
    {
        $messaging = Mockery::mock(Messaging::class);

        $messaging->shouldReceive('send')
            ->once()
            ->with(Mockery::type(CloudMessage::class))
            ->andReturn([]);

        $this->app->instance('firebase.messaging', $messaging);

        (new FirebasePushNotification())->sendPushNotification(
            'jeton-appareil',
            'Nouvelle course',
            'Une commande vous attend',
        );
    }

    public function test_elle_envoie_a_plusieurs_appareils_apres_validation(): void
    {
        $messaging = Mockery::mock(Messaging::class);

        // Le SDK filtre d'abord les jetons morts : un appareil desinstalle
        // garde son jeton en base longtemps apres.
        $messaging->shouldReceive('validateRegistrationTokens')
            ->once()
            ->andReturn(['valid' => ['jeton-a'], 'unknown' => [], 'invalid' => ['jeton-b']]);

        $messaging->shouldReceive('sendMulticast')
            ->once()
            ->with(Mockery::type(CloudMessage::class), ['jeton-a'])
            ->andReturn(MulticastSendReport::withItems([]));

        $this->app->instance('firebase.messaging', $messaging);

        (new FirebasePushNotification())->sendPushNotificationMultiUser(
            ['jeton-a', 'jeton-b'],
            'Commande acceptée',
            'Votre repas est en préparation',
        );
    }

    public function test_elle_n_envoie_rien_si_aucun_jeton_n_est_valide(): void
    {
        $messaging = Mockery::mock(Messaging::class);

        $messaging->shouldReceive('validateRegistrationTokens')
            ->once()
            ->andReturn(['valid' => [], 'unknown' => [], 'invalid' => ['mort']]);

        $messaging->shouldNotReceive('sendMulticast');

        $this->app->instance('firebase.messaging', $messaging);

        (new FirebasePushNotification())->sendPushNotificationMultiUser(
            ['mort'],
            'Titre',
            'Corps',
        );
    }
}
