<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un code d'autorisation : un aller simple, valable une minute, une seule fois.
 */
class OauthAuthorizationCode extends Model
{
    protected $guarded = [];

    protected $casts = [
        'scopes' => 'array',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(OauthClient::class, 'oauth_client_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Le code en clair ne vit que dans l'URL de redirection ; en base on ne
     * garde que cette empreinte. 32 octets aléatoires n'ont rien à craindre
     * d'un SHA-256 sans sel : il n'y a pas d'espace de recherche à parcourir.
     */
    public static function empreinte(string $codeEnClair): string
    {
        return hash('sha256', $codeEnClair);
    }
}
