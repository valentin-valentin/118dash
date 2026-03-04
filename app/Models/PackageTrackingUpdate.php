<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackageTrackingUpdate extends Model
{
    use HasFactory;

    protected $fillable = [
        'package_tracking_id',
        'status',
        'location',
        'occurred_at',
        'details',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'details' => 'array',
    ];

    public function tracking(): BelongsTo
    {
        return $this->belongsTo(PackageTracking::class, 'package_tracking_id');
    }
}
