<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;


class User extends Authenticatable implements FilamentUser
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'last_name',
        'name',
        'email',
        'password',
        "number_street",
        "street",
        'principal_adresse',
        'town_id',
        'devices',
        'api_token',
        'creation_token',
        'mobile_permissions',
        'type_user',
        "google_id",
        "social_media_avatar"
    ];


    /**
     * Acces au panneau d'administration.
     *
     * Cette methode existait deja, mais elle n'etait jamais appelee : le
     * modele n'implementait pas FilamentUser. Le middleware de Filament
     * retombait alors sur sa seconde branche —
     * `abort_if(config('app.env') !== 'local', 403)` — c'est-a-dire : en
     * local, TOUT utilisateur authentifie entrait dans l'administration ;
     * ailleurs, personne. Le controle d'acces n'a donc jamais fonctionne, et
     * ne s'est vu qu'au premier deploiement en APP_ENV=production.
     *
     * Elle type-hintait par ailleurs Filament\Tables\Columns\Layout\Panel,
     * une colonne de tableau, au lieu de Filament\Panel. L'import fautif
     * n'avait aucune consequence tant que la methode restait morte.
     *
     * $this plutot que auth()->user() : c'est l'utilisateur que Filament
     * teste qui doit repondre, pas celui de la session courante.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasRole('super_admin');
    }


    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        /*
         * register() et activation() renvoient le modele complet : sans ces
         * deux entrees, le code d'activation etait retourne dans la reponse
         * d'inscription. La verification par email devenait decorative,
         * puisqu'il suffisait de lire la reponse pour obtenir le code.
         *
         * Aucun client n'en fait usage : ni l'application mobile ni le site
         * ne lisent ce champ.
         */
        'otp',
        'otp_expire_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        // Sans ce cast, otp_expire_at est manipule comme une chaine et toute
        // comparaison de date depend du bon vouloir du parseur.
        'otp_expire_at' => 'datetime',
        'password' => 'hashed',
        'devices' => 'array',
        'mobile_permissions' => 'array'
    ];

    public function restaurant(): HasMany
    {
        return $this->hasMany(Restaurant::class);
    }

    public function commande(): HasMany
    {
        return $this->hasMany(Commande::class);
    }

    public function town(): BelongsTo
    {
        return $this->belongsTo(Town::class);
    }


    public function delivrery_driver(): hasMany

    {
        return $this->hasMany(DelivreryDriver::class);
    }

    public function fullName()
    {
        return $this->first_name . ' ' . $this->name;
    }
}
