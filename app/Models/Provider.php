<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Provider extends Model
{
    use HasFactory;

    protected $fillable = [
        'enabled',
        'name',
        'driver',
        'config',
        'payout',
        'color',
        'sip_number_format',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'config' => 'array',
        'payout' => 'decimal:2',
    ];
}
