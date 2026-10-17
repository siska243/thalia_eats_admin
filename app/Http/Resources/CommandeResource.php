<?php

namespace App\Http\Resources;

use App\Models\Commande;
use App\Models\Currency;
use App\Models\Restaurant;
use App\Wrappers\Cipher;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @property Commande $resource
 */
class CommandeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // return parent::toArray($request);
        return [
            'uid' => Cipher::encrypt($this->resource->id),
            'adresse' => $this->resource->adresse_delivery,
            'created_at' => $this->resource->created_at,
            /*
             * L'echeance de reglement, pour le compte a rebours du client.
             *
             * Rendue seulement tant qu'un reglement est reellement attendu.
             * La colonne garde la valeur posee a la derniere ecriture : la
             * rendre telle quelle ferait decompter une commande deja livree,
             * ou decompter a l'envers une commande deja perimee. Meme regle
             * que sur `BookingResource` — c'est le serveur qui decide ce qui
             * a un sens, pas l'ecran.
             */
            'expires_at' => $this->resource->attendUnReglement()
                ? $this->resource->expires_at
                : null,
            'reference_adresse' => $this->resource->reference_adresse,
            // Chaque code n'est communique qu'a la partie qui doit le
            // detenir. Le livreur les recevait tous les deux : il pouvait
            // declarer une commande recuperee puis livree sans jamais voir ni
            // le restaurant ni le client, ce qui privait les deux
            // confirmations de tout effet.
            'code_confirmation' => $this->estLeClient($request)
                ? $this->resource->code_confirmation
                : null,
            'restaurant_code_confirmation' => $this->estRestaurateur($request)
                ? $this->resource->code_confirmation_restaurant
                : null,
            'address_delivery' => $this->resource->adresse_delivery,
            'street' => $this->resource->street,
            'number_street' => $this->resource->number_street,
            // La colonne s'appelle delivery_at ; lire delivrery_at visait un
            // attribut inexistant, et le champ partait donc toujours a null.
            // Seule la lecture etait fautive : la cle de reponse, elle, est
            // correcte et ne bouge pas.
            'delivery_at' => $this->resource->delivery_at,
            'cancel_at' => $this->resource->cancel_at,
            'accepted_at' => $this->resource->accepted_at,
            'global_price' => $this->resource->global_price,
            // Le total etait la seule ligne chiffree exposee : l'ecran ne
            // pouvait pas montrer le prix des plats sans le recalculer, et
            // deux calculs pour un meme montant finissent toujours par
            // diverger. Les lignes font foi.
            'sous_total' => $this->resource->sousTotal(),
            // La devise d'une commande n'etait exposee nulle part : chaque
            // client la deduisait de `products[0].currency`. Les commandes sans
            // produit — il en existe en base — affichaient donc leur total nu,
            // « 2,3 » sans unite, aussi bien sur le web que sur le telephone.
            // Une commande porte un montant, elle doit porter sa monnaie.
            'currency' => $this->devise(),
            'price_delivery' => $this->resource->price_delivery,
            'price_service' => $this->resource->price_service,
            'town_id' => new TownResource($this->resource->town),
            /*
             * Le statut REEL, expiration comprise.
             *
             * La ressource rendait la relation brute : une commande dont le
             * delai etait depasse continuait donc d'annoncer « en attente de
             * paiement ». Le client la voyait dans sa liste, croyait pouvoir
             * la regler, et ne pouvait rien en faire. `BookingResource` passe
             * deja par le statut effectif de son cote ; les deux disent
             * desormais la meme chose.
             *
             * La tache planifiee finira par ecrire ce statut en base, mais
             * l'API n'a pas a mentir en l'attendant.
             */
            'status' => new StatusResource($this->resource->statutLisible()),
            'reference' => $this->resource->refernce,
            'lat' => $this->resource->lat,
            'long' => $this->resource->long,
            'recipient_name' => $this->resource->recipient_name,
            'recipient_phone' => $this->resource->recipient_phone,
            'reference_address' => $this->resource->adresse,
            'delivrery_driver_id' => $this->whenLoaded('delivrery_driver', new DelivreryResource($this->resource->delivrery_driver)),
            'reception' => $this->resource->reception,
            'user' => $this->whenLoaded('user', new UserResource($this->resource->user)),
            'reference_paiement' => $this->resource->reference_paiement,
            'time_restaurant' => $this->resource->time_restaurant,
            'time_delivery' => $this->resource->time_delivery,
            'date_time_restautant' => $this->resource->time_restaurant ? self::customTime($this->resource->accepted_at, $this->resource->time_restaurant) : null,
            'date_time_delivery' => $this->resource->time_delivery ? self::customTime($this->resource->accepted_at, $this->resource->time_delivery) : null,
            'products' => $this->whenLoaded('product', CommandeProductResource::collection($this->resource->product)),
            'user_delivery_complet_adress' => "{$this->resource->adresse_delivery}, {$this->resource->town?->title} {$this->resource->town?->city?->title}",
            'mask_address' => Str::mask("{$this->resource->adresse_delivery}, {$this->resource->town?->title}", '*', 3),
        ];
    }

    /**
     * La devise de la commande.
     *
     * La regle vit sur le modele : le courriel de confirmation en a besoin
     * aussi, et une seconde lecture aurait fini par diverger de celle-ci.
     */
    private function devise(): ?Currency
    {
        return $this->resource->devise();
    }

    public static function customTime($date, $time)
    {
        $date = Carbon::parse($date);
        $time = Carbon::parse($time);

        $date->setTime($time->hour, $time->minute, $time->second);

        return $date->toDateTimeString();
    }

    private function estLeClient(Request $request): bool
    {
        return $request->user()?->id === $this->resource->user_id;
    }

    /**
     * Le proprietaire d'un restaurant. La reponse est memorisee pour la duree
     * de la requete : une collection de commandes interrogerait sinon la table
     * une fois par ligne.
     */
    private function estRestaurateur(Request $request): bool
    {
        $user = $request->user();

        if (! $user) {
            return false;
        }

        static $cache = [];

        return $cache[$user->id] ??= Restaurant::query()->where('user_id', $user->id)->exists();
    }
}
