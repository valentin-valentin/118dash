<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    use HasFactory;

    protected $fillable = [
        'callcenter_id',
        'enabled',
        'name',
        'pin',
        'lang',
        'theme',
        'image',
        'icon',
        'voxnode_username',
        'voxnode_password',
        'last_ping_at',
        'online',
        'active_call_id',
        'break_requested_at',
        'break_active_at',
        'break_type',
        'logout_requested_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'online' => 'boolean',
        'last_ping_at' => 'datetime',
        'break_requested_at' => 'datetime',
        'break_active_at' => 'datetime',
        'logout_requested_at' => 'datetime',
    ];

    protected $hidden = [
        'pin',
        'voxnode_password',
    ];

    public function callcenter(): BelongsTo
    {
        return $this->belongsTo(Callcenter::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(Call::class);
    }

    public function activeCall(): BelongsTo
    {
        return $this->belongsTo(Call::class, 'active_call_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AgentLog::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(AgentAttendance::class);
    }

    public function consoleLogs(): HasMany
    {
        return $this->hasMany(AgentConsoleLog::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(AgentRating::class);
    }
}
