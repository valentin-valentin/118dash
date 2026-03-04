<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailCall extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_id',
        'call_id',
    ];

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }
}
