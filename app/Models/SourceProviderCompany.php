<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceProviderCompany extends Model
{
    use HasFactory;

    protected $table = 'sources_providers_companies';

    protected $fillable = [
        'source_id',
        'providers_companies_id',
        'payout',
        'weight',
    ];

    protected $casts = [
        'payout' => 'decimal:2',
        'weight' => 'integer',
    ];

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function providerCompany(): BelongsTo
    {
        return $this->belongsTo(ProviderCompany::class, 'providers_companies_id');
    }
}
