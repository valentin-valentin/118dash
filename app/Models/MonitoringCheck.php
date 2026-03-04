<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MonitoringCheck extends Model
{
    use HasFactory;

    protected $fillable = [
        'monitoring_id',
        'url',
        'sms_l',
        'sms_j',
        'sms_v',
        'time',
        'http_code',
        'response',
        'successful',
    ];

    protected $casts = [
        'sms_l' => 'boolean',
        'sms_j' => 'boolean',
        'sms_v' => 'boolean',
        'successful' => 'boolean',
    ];

    public function monitoring(): BelongsTo
    {
        return $this->belongsTo(Monitoring::class);
    }
}
