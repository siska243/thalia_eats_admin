<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un logiciel assistant enregistré dynamiquement (RFC 7591).
 *
 * Il n'y a pas de secret : Claude et ChatGPT sont des clients publics. Un
 * secret délivré à un logiciel installé chez l'utilisateur n'est pas un secret,
 * il donnerait seulement l'impression qu'on en vérifie un.
 */
class OauthClient extends Model
{
    protected $guarded = [];

    protected $casts = [
        'redirect_uris' => 'array',
        'grant_types' => 'array',
        'response_types' => 'array',
    ];

    public function authorizationCodes(): HasMany
    {
        return $this->hasMany(OauthAuthorizationCode::class);
    }

    /**
     * Correspondance EXACTE, jamais un préfixe.
     *
     * Une comparaison par préfixe laisserait passer
     * « https://claude.ai/callback.pirate.example.com » là où le client a
     * enregistré « https://claude.ai/callback » : c'est exactement comme cela
     * qu'un code d'autorisation part chez quelqu'un d'autre.
     */
    public function accepteRedirection(?string $redirectUri): bool
    {
        if ($redirectUri === null || $redirectUri === '') {
            return false;
        }

        foreach ((array) $this->redirect_uris as $enregistree) {
            if (hash_equals((string) $enregistree, $redirectUri)) {
                return true;
            }
        }

        return false;
    }
}
