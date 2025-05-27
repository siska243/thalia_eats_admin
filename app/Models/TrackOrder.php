<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TrackOrder extends Model
{
    //
    use HasFactory;

    protected $casts=[
        "location_delivery"=>"array",
        "location_customer"=>"array",
        "delivered"=>"boolean"
    ];
}
