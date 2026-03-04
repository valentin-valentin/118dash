<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailRefund extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_id',
        'refund_id',
    ];

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }
}
