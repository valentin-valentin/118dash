<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RefundPhonenumber extends Model
{
    use HasFactory;

    protected $fillable = [
        'refund_id',
        'phonenumber',
    ];

    public function refund(): BelongsTo
    {
        return $this->belongsTo(Refund::class);
    }
}
