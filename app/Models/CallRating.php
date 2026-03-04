<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallRating extends Model
{
    use HasFactory;

    protected $fillable = [
        'call_id',
        'who_rate',
        'rate',
    ];

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}
