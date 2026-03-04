<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Refund extends Model
{
    use HasFactory;

    protected $fillable = [
        'called',
        'requested',
        'requested_at',
        'validated',
        'validated_at',
        'refunded',
        'refunded_at',
        'refund_method',
        'refund_transfer_iban',
        'refund_transfer_swift',
        'refund_transfer_account_owner',
        'refund_paypal_email',
        'refund_calls',
        'refund_amount',
    ];

    protected $casts = [
        'requested' => 'boolean',
        'requested_at' => 'datetime',
        'validated' => 'boolean',
        'validated_at' => 'datetime',
        'refunded' => 'boolean',
        'refunded_at' => 'datetime',
        'refund_amount' => 'decimal:2',
    ];

    public function phonenumbers(): HasMany
    {
        return $this->hasMany(RefundPhonenumber::class);
    }
}
