<?php

namespace App\Services\Notification;

use App\Helpers\CurrentHelpers;
use App\Mail\CommandePayeeMail;
use App\Models\Commande;
use App\Models\User;
use App\Settings\NotificationSettings;
use App\Wrappers\FirebasePushNotification;
use Filament\Notifications\Notification as NotificationFilament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Prevenir, quand une commande est payee.
 *
 * AVANT, une commande payee n'envoyait qu'UN signal : une notification push
 * au restaurateur, et seulement s'il avait un jeton. Ni l'administration ni
 * le client n'apprenaient rien — une commande pouvait arriver sans que
 * personne au bureau le sache.
 *
 * ET CE SIGNAL ETAIT ECRIT DEUX FOIS : le webhook de la passerelle et la
 * verification manuelle portaient chacun leur copie du meme bout de code.
 * Deux lectures de la meme regle finissent par diverger — c'est deja arrive
 * sur les horaires et sur la portee de livraison. Tout est ici, une fois.
 *
 * IDEMPOTENT. Le webhook peut etre rejoue — son propre commentaire le dit —
 * et rien n'empecherait sinon d'envoyer trois fois le meme courriel. Meme
 * protection que le stock : une colonne posee sous verrou.
 *
 * RIEN NE DOIT FAIRE ECHOUER UN PAIEMENT. Un serveur de courriel muet, une
 * adresse mal saisie, Firebase injoignable : tout est attrape et journalise.
 * La commande est payee, c'est ce qui compte ; un courriel perdu se renvoie,
 * un paiement perdu non.
 */
class NotifierCommandePayee
{
    public function __invoke(Commande $commande): void
    {
        if (! $this->marquerEnvoyee($commande)) {
            return;
        }

        $commande->loadMissing([
            'user',
            'commande_products.product.restaurant.user',
            'product.currency',
        ]);

        $restaurateur = CurrentHelpers::getUserByOrder($commande);

        $this->pushAuRestaurateur($restaurateur);
        $this->courrielsAdmin($commande);
        $this->cloche($commande);
        $this->courrielClient($commande);
        $this->courrielRestaurant($commande, $restaurateur);
    }

    /**
     * Pose la marque sous verrou, et dit si l'envoi doit avoir lieu.
     *
     * Relue dans la transaction : la marque lue avant la section critique ne
     * prouve plus rien une fois qu'on y entre — deux webhooks simultanes
     * passeraient tous les deux.
     */
    private function marquerEnvoyee(Commande $commande): bool
    {
        return DB::transaction(function () use ($commande): bool {
            $verrouillee = Commande::query()->whereKey($commande->getKey())->lockForUpdate()->first();

            if (! $verrouillee || $verrouillee->notifiee_at) {
                return false;
            }

            $verrouillee->notifiee_at = now();
            $verrouillee->save();

            $commande->notifiee_at = $verrouillee->notifiee_at;

            return true;
        });
    }

    private function pushAuRestaurateur(?User $restaurateur): void
    {
        if (! $restaurateur?->expo_push_token) {
            return;
        }

        $this->sansCasser('push restaurateur', function () use ($restaurateur): void {
            $push = new FirebasePushNotification;
            $push->sendPushNotification(
                $restaurateur->expo_push_token,
                'Nouvelle commande',
                json_encode(['action' => 'new-order']),
            );

            FirebasePushNotification::sendNotification(
                $restaurateur->expo_push_token,
                'Thalia Eats',
                'Nouvelle commande',
            );
        });
    }

    private function courrielsAdmin(Commande $commande): void
    {
        foreach (NotificationSettings::destinatairesAdmin() as $adresse) {
            $this->sansCasser("courriel admin {$adresse}", fn () => Mail::to($adresse)
                ->send(new CommandePayeeMail($commande, CommandePayeeMail::POUR_ADMIN)));
        }
    }

    /**
     * La cloche du panneau d'administration.
     *
     * « Push cote admin » veut dire ceci : l'administration est un
     * back-office web, pas une application mobile. Les notifications de base
     * de donnees sont deja activees sur le panneau.
     *
     * Elle part a ceux qui ont le droit de voir les commandes, et a eux
     * seuls : annoncer une commande a quelqu'un qui ne peut pas l'ouvrir est
     * une impasse.
     */
    private function cloche(Commande $commande): void
    {
        $this->sansCasser('cloche admin', function () use ($commande): void {
            $destinataires = User::query()
                ->whereHas('roles')
                ->get()
                ->filter(fn (User $user): bool => $user->can('ViewAny:Commande'));

            if ($destinataires->isEmpty()) {
                return;
            }

            NotificationFilament::make()
                ->title('Nouvelle commande payée')
                ->body("Commande {$commande->refernce} — ".$this->montant($commande))
                ->success()
                ->sendToDatabase($destinataires);
        });
    }

    private function courrielClient(Commande $commande): void
    {
        if (! NotificationSettings::copieClient() || ! $commande->user?->email) {
            return;
        }

        $this->sansCasser('courriel client', fn () => Mail::to($commande->user->email)
            ->send(new CommandePayeeMail($commande, CommandePayeeMail::POUR_CLIENT)));
    }

    private function courrielRestaurant(Commande $commande, ?User $restaurateur): void
    {
        if (! NotificationSettings::copieRestaurant() || ! $restaurateur?->email) {
            return;
        }

        $this->sansCasser('courriel restaurant', fn () => Mail::to($restaurateur->email)
            ->send(new CommandePayeeMail($commande, CommandePayeeMail::POUR_RESTAURANT)));
    }

    private function montant(Commande $commande): string
    {
        // La devise vit sur le modele : la recalculer ici en ferait une
        // seconde lecture, et c'est ainsi qu'un montant finit par s'afficher
        // en dollars dans un courriel et en francs dans l'application.
        return trim(number_format((float) $commande->global_price, 2, ',', ' ')
            .' '.($commande->devise()?->code ?? ''));
    }

    /**
     * Un envoi rate ne fait pas echouer le paiement.
     *
     * Le journal garde la trace : sans elle, un serveur de courriel muet
     * pendant une semaine passerait inapercu.
     */
    private function sansCasser(string $quoi, callable $envoi): void
    {
        try {
            $envoi();
        } catch (\Throwable $exception) {
            Log::warning("Notification de commande non envoyee : {$quoi}", [
                'erreur' => $exception->getMessage(),
            ]);
        }
    }
}
