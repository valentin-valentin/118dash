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
    ];

    protected $casts = [
        'fingerprint' => 'boolean',
        'only_dedicated_phonenumber' => 'boolean',
    ];

    public function sourceProviderCompanies(): HasMany
    {
        return $this->hasMany(SourceProviderCompany::class);
    }
}
