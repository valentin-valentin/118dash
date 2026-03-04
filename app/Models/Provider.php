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
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'config' => 'array',
        'payout' => 'decimal:2',
    ];
}
