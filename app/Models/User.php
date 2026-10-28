<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\Email\Contracts\HasEmailAuthentication;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, HasEmailAuthentication
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

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
        'number_street',
        'street',
        'principal_adresse',
        'town_id',
        'devices',
        'api_token',
        'creation_token',
        'mobile_permissions',
        'type_user',
        'google_id',
        'social_media_avatar',
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
        // Le second facteur ne sort jamais dans une reponse d'API.
        'app_authentication_secret',
        'app_authentication_recovery_codes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'blocked_at' => 'datetime',
        'account_deleted_at' => 'datetime',
        /*
         * Le secret TOTP et les codes de secours sont CHIFFRES en base.
         *
         * Une fuite de la table livrerait sinon le second facteur en meme
         * temps que le premier, et la double authentification n'aurait plus
         * aucun sens. `encrypted:array` chiffre aussi la liste des codes.
         */
        'app_authentication_secret' => 'encrypted',
        'app_authentication_recovery_codes' => 'encrypted:array',
        'has_email_authentication' => 'boolean',
        // Sans ce cast, otp_expire_at est manipule comme une chaine et toute
        // comparaison de date depend du bon vouloir du parseur.
        'otp_expire_at' => 'datetime',
        'password' => 'hashed',
        'devices' => 'array',
        'mobile_permissions' => 'array',
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

    public function delivrery_driver(): HasMany
    {
        return $this->hasMany(DelivreryDriver::class);
    }

    public function fullName()
    {
        return $this->first_name.' '.$this->name;
    }

    // --- Double authentification -------------------------------------------

    /*
     * Les deux facteurs que Filament sait deja faire : une application
     * d'authentification (TOTP) et un code envoye par courriel. Le
     * proprietaire : « tu peux mettre les 2, c'est a l'utilisateur de
     * choisir ».
     *
     * Rien n'est impose : tant que personne n'active rien, la connexion se
     * passe exactement comme avant.
     */

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();
    }

    /**
     * Le nom affiche dans l'application d'authentification.
     *
     * L'adresse, et non le prenom : on y voit souvent une dizaine de
     * comptes, et « Eva » ne dit pas lequel.
     */
    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /**
     * @return ?array<string>
     */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /**
     * @param  ?array<string>  $codes
     */
    public function saveAppAuthenticationRecoveryCodes(?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }

    public function hasEmailAuthentication(): bool
    {
        return (bool) $this->has_email_authentication;
    }

    public function toggleEmailAuthentication(bool $condition): void
    {
        $this->has_email_authentication = $condition;
        $this->save();
    }

    // --- Validation et blocage d'un compte ---------------------------------

    /**
     * Ce compte est-il bloque ?
     *
     * La regle vit ici, une seule fois : la connexion, le garde de l'API et
     * l'administration posent la meme question, et trois lectures de
     * `blocked_at` finiraient par diverger.
     *
     * `blocked_at` est une colonne NEUVE, nullable. `is_active` existait,
     * mais vaut faux pour la quasi-totalite des comptes et n'est lu nulle
     * part : s'en servir aurait mis presque tous les clients dehors.
     */
    public function estBloque(): bool
    {
        return $this->blocked_at !== null;
    }

    /**
     * Bloque le compte et le met dehors tout de suite.
     *
     * Revoquer les jetons n'est pas un supplement : sans cela, un compte
     * bloque reste a l'interieur aussi longtemps que son telephone ne se
     * deconnecte pas, et c'est precisement le compte qu'on voulait sortir.
     */
    public function bloquer(?string $raison = null): void
    {
        $this->forceFill([
            'blocked_at' => now(),
            'blocked_reason' => $raison,
        ])->save();

        $this->tokens()->delete();
    }

    public function debloquer(): void
    {
        $this->forceFill(['blocked_at' => null, 'blocked_reason' => null])->save();
    }

    // --- Suppression du compte par son titulaire ---------------------------

    public function compteSupprime(): bool
    {
        return $this->account_deleted_at !== null;
    }

    /**
     * Ce qui retient encore ce compte, dit en francais.
     *
     * Rend la liste des raisons de refuser la suppression. Vide, le compte
     * peut partir. Le proprietaire : « on ne peut pas supprimer si une
     * commande est en cours, il doit soit annuler sa commande soit attendre
     * que ca se termine. »
     *
     * Les deux listes de statuts viennent des modeles concernes et ne sont
     * pas recopiees ici : `Commande::STATUTS_EN_COURS` et
     * `Booking::STATUTS_ENGAGEANTS`. Le filtre repasse par `statutEffectif()`
     * apres la requete, parce qu'une commande ou une reservation perimee
     * porte encore son ancien `status_id` en base alors qu'elle ne retient
     * plus rien — c'est l'ecart connu des lignes heritees.
     */
    public function engagementsEnCours(): array
    {
        $raisons = [];

        $commandes = $this->commande()
            ->whereIn('status_id', Commande::STATUTS_EN_COURS)
            ->nonAnnulee()
            ->get()
            ->filter(fn (Commande $commande): bool => in_array(
                $commande->statutEffectif(),
                Commande::STATUTS_EN_COURS,
                true
            ));

        if ($commandes->isNotEmpty()) {
            $raisons[] = $commandes->count() === 1
                ? 'une commande est encore en cours'
                : $commandes->count().' commandes sont encore en cours';
        }

        $courses = Booking::query()
            ->where('user_id', $this->id)
            ->whereIn('status', Booking::STATUTS_ENGAGEANTS)
            ->get()
            ->filter(fn (Booking $course): bool => in_array(
                $course->statutEffectif(),
                Booking::STATUTS_ENGAGEANTS,
                true
            ));

        if ($courses->isNotEmpty()) {
            $raisons[] = $courses->count() === 1
                ? 'une location est encore en cours'
                : $courses->count().' locations sont encore en cours';
        }

        return $raisons;
    }

    /**
     * Anonymise le compte et le ferme.
     *
     * La ligne `users` reste : commandes, paiements et livraisons la
     * referencent, et les effacer effacerait la comptabilite. Ce qui part,
     * c'est tout ce qui designe une personne.
     *
     * L'adresse est remplacee plutot que videe : la colonne est UNIQUE, et
     * deux comptes supprimes se marcheraient dessus. Le domaine `.invalid`
     * est reserve par la RFC 2606, aucun courriel ne pourra jamais y partir.
     *
     * Le mot de passe est remplace par une valeur aleatoire que personne ne
     * connait : le laisser en place ferait d'un ancien mot de passe une cle
     * encore valable le jour ou le blocage sauterait.
     *
     * `bloquer()` ferme la porte, et c'est volontairement le meme chemin que
     * le blocage administratif : il efface deja les jetons Sanctum, et
     * `estBloque()` est relu a la connexion comme par le garde
     * `RefuserCompteBloque`. Une suppression n'a pas a inventer son propre
     * verrou — un second verrou serait un second endroit ou se tromper.
     *
     * Le restaurant n'est pas touche, seul son gerant s'en detache : le
     * catalogue, les commandes et l'historique de l'enseigne survivent a
     * celui qui la tenait.
     */
    public function supprimerLeCompte(): void
    {
        DB::transaction(function (): void {
            $this->restaurant()->update(['user_id' => null]);

            UserAdresse::query()->where('user_id', $this->id)->delete();
            FcmToken::query()->where('user_id', $this->id)->delete();
            ExpoPushToken::query()->where('user_id', $this->id)->delete();
            UserNotificationTokens::query()->where('user_id', $this->id)->delete();

            $this->forceFill([
                'name' => 'Compte supprimé',
                'last_name' => null,
                'email' => 'supprime+'.$this->id.'@thaliaeats.invalid',
                'slug' => 'compte-supprime-'.$this->id,
                'phone' => null,
                'street' => null,
                'number_street' => null,
                'principal_adresse' => null,
                'town_id' => null,
                'google_id' => null,
                'social_media_avatar' => null,
                'expo_push_token' => null,
                'devices' => null,
                'otp' => null,
                'otp_expire_at' => null,
                'api_token' => null,
                'password' => Hash::make(Str::random(64)),
                'account_deleted_at' => now(),
            ])->save();

            $this->bloquer('Compte supprimé par son titulaire');
        });
    }

    /**
     * Ce compte doit-il confirmer son adresse avant d'entrer ?
     *
     * Meme raison que `estBloque()` juste au-dessus : la question se pose a
     * deux endroits — la connexion, qui refuse d'emettre un jeton, et le
     * garde ExigerAdresseConfirmee, qui ferme l'API. Deux lectures de la
     * date pivot finiraient par diverger, et l'une des deux laisserait
     * passer ce que l'autre refuse.
     *
     * La date vient de la configuration et se pose au deploiement. Vide, la
     * regle ne s'applique a personne : c'est l'etat d'avant, et il suffit de
     * ne rien regler pour revenir en arriere. Seuls les comptes crees a
     * partir de ce moment sont tenus de confirmer — dix-neuf des vingt-trois
     * comptes existants ne l'ont jamais fait, et les fermer du jour au
     * lendemain mettrait de vrais clients dehors.
     */
    public function doitConfirmerSonAdresse(): bool
    {
        if ($this->email_verified_at !== null) {
            return false;
        }

        $pivot = config('securite.confirmation_email.exigee_depuis');

        if (! $pivot) {
            return false;
        }

        // Un compte sans date de creation est forcement ancien : on ne bloque
        // personne sur une donnee manquante.
        return $this->created_at !== null
            && $this->created_at->greaterThanOrEqualTo(Carbon::parse($pivot));
    }

    /**
     * Marque l'adresse comme confirmee, depuis l'administration.
     *
     * Le meme champ que la confirmation par code : valider a la main et
     * valider par email doivent produire exactement le meme compte, sinon le
     * garde de [[T146]] laisserait passer l'un et pas l'autre.
     *
     * Une date deja posee n'est pas repoussee : elle dit quand l'adresse a
     * ete confirmee, pas combien de fois on a clique.
     */
    public function marquerAdresseConfirmee(): void
    {
        if ($this->email_verified_at !== null) {
            return;
        }

        $this->forceFill(['email_verified_at' => now()])->save();
    }
}
