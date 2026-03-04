<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentConsoleLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'callcenter_id',
        'agent_id',
        'log',
    ];

    protected $casts = [
        'log' => 'array',
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
