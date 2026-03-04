<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PackageTracking extends Model
{
    use HasFactory;

    protected $fillable = [
        'package_carrier_id',
        'seventeentrack_origin',
        'number',
        'seventeentrack_carrier',
        'tracking',
    ];

    protected $casts = [
        'tracking' => 'array',
    ];

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(PackageCarrier::class, 'package_carrier_id');
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(PackageTrackingUpdate::class);
    }
}
