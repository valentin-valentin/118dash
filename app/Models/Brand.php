<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    use HasFactory;

    protected $fillable = [
        'enabled',
        'type',
        'name',
        'country',
        'phonenumber',
        'note',
        'scenario',
        'can_transfer',
        'can_offer_callback',
        'display_118500',
        'image',
        'icon',
        'display_time',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'can_transfer' => 'boolean',
        'can_offer_callback' => 'boolean',
        'display_118500' => 'boolean',
        'display_time' => 'boolean',
    ];

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class, 'brand_phonenumber', 'phonenumber');
    }
}
