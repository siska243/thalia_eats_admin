<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use Filament\Tables\Columns\Layout\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;


class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
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
        'type_user'
    ];


    public function canAccessPanel(Panel $panel): bool
    {


        if(auth()->user()->hasRole('super_admin')) return true;


        return false;
    }



    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'devices'=>'array',
        'mobile_permissions'=>'array'
    ];

    public function restaurant():HasMany
    {
      return $this->hasMany(Restaurant::class);
    }

    public function commande():HasMany
    {
        return $this->hasMany(Commande::class);
    }

    public function town():BelongsTo
    {
        return $this->belongsTo(Town::class);
    }



    public  function  delivrery_driver():hasMany

    {
        return  $this->hasMany(DelivreryDriver::class);
    }

    public function fullName(){
        return $this->first_name.' '.$this->name;
    }
}
