<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CallWeblog extends Model
{
    use HasFactory;

    protected $fillable = [
        'call_id',
        'type',
        'ip',
        'user_agent',
    ];

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}
