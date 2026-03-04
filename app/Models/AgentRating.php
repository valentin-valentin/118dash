<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRating extends Model
{
    use HasFactory;

    protected $fillable = [
        'agent',
        'who_rate',
        'rate',
    ];

    protected $casts = [
        'rate' => 'array',
    ];

    public function agentModel(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'agent', 'name');
    }
}
