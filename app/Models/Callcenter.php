<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Callcenter extends Model
{
    use HasFactory;

    protected $fillable = [
        'enabled',
        'name',
        'lang',
        'manager_pin',
        'manager_lang',
        'manager_theme',
        'manager_timezone',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }
}
