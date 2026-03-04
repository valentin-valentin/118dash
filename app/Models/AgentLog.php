<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AgentLog extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'callcenter_id',
        'agent_id',
        'type',
        'initiator',
        'action',
        'value',
        'call_id',
        'brand_id',
    ];

    public function callcenter(): BelongsTo
    {
        return $this->belongsTo(Callcenter::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(Call::class);
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
