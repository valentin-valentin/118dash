<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProviderCompany extends Model
{
    use HasFactory;

    protected $table = 'providers_companies';

    protected $fillable = [
        'provider_id',
        'company_id',
        'payout',
        'config',
    ];

    protected $casts = [
        'payout' => 'decimal:2',
        'config' => 'array',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function sourceProviderCompanies(): HasMany
    {
        return $this->hasMany(SourceProviderCompany::class, 'providers_companies_id');
    }
}
