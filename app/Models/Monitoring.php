<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Monitoring extends Model
{
    use HasFactory;

    protected $fillable = [
        'enabled',
        'url',
        'sms_l',
        'sms_j',
        'sms_v',
        'last_check_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'sms_l' => 'boolean',
        'sms_j' => 'boolean',
        'sms_v' => 'boolean',
        'last_check_at' => 'datetime',
    ];

    public function checks(): HasMany
    {
        return $this->hasMany(MonitoringCheck::class);
    }
}
