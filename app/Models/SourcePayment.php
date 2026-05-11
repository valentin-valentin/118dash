<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourcePayment extends Model
{
    use HasFactory;

    const TYPE_CREDIT = 'credit';
    const TYPE_DEBIT = 'debit';

    protected $fillable = [
        'source_id',
        'amount',
        'type',
        'description',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }
}
