<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Source extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'api_key',
        'fingerprint',
        'only_dedicated_phonenumber',
        'color',
        'max_concurrent_numbers',
        'payout_call',
        'payout_minute',
        'display_duration_minutes',
        'real_duration_minutes',
    ];

    protected $casts = [
        'fingerprint' => 'boolean',
        'only_dedicated_phonenumber' => 'boolean',
        'payout_call' => 'decimal:2',
        'payout_minute' => 'decimal:2',
        'max_concurrent_numbers' => 'integer',
        'display_duration_minutes' => 'integer',
        'real_duration_minutes' => 'integer',
    ];

    public function sourceProviderCompanies(): HasMany
    {
        return $this->hasMany(SourceProviderCompany::class);
    }
}
