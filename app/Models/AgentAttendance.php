<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AgentAttendance extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'callcenter_id',
        'agent_id',
        'action',
        'initiator',
        'type',
        'duration',
        'ended_at',
    ];

    protected $casts = [
        'ended_at' => 'datetime',
    ];

    public function callcenter(): BelongsTo
    {
        return $this->belongsTo(Callcenter::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
