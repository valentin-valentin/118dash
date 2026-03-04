<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Blacklist extends Model
{
    use HasFactory;

    protected $table = 'blacklist';

    protected $fillable = [
        'phonenumber',
        'source',
        'note',
    ];

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }
}
