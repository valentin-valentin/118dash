<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Zipcode extends Model
{
    use HasFactory;

    protected $fillable = [
        'country_code',
        'zipcode',
        'city',
        'state',
        'state_code',
        'province',
        'province_code',
        'community',
        'community_code',
        'latitude',
        'longitude',
    ];
}
