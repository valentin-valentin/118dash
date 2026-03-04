<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IndividualSearch extends Model
{
    use HasFactory;

    protected $fillable = [
        'source',
        'lastname',
        'firstname',
        'country',
        'zipcode',
        'city',
        'state',
        'state_code',
        'province',
        'province_code',
        'community',
        'community_code',
        'results',
    ];

    protected $casts = [
        'results' => 'array',
    ];

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }
}
