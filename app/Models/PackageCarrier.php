<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackageCarrier extends Model
{
    use HasFactory;

    protected $fillable = [
        'seventeentrack_id',
        'name',
        'brand_id',
        'home_url',
        'tracking_url',
        'additional_parameter',
        'additional_parameter_name',
        'additional_parameter_name_de',
        'additional_parameter_name_es',
        'additional_parameter_name_fr',
        'additional_parameter_name_nl',
        'additional_parameter_example',
        'additional_parameter_regex',
        'alternate_phonenumber',
    ];

    protected $casts = [
        'additional_parameter' => 'boolean',
    ];

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function trackings(): HasMany
    {
        return $this->hasMany(PackageTracking::class);
    }
}
