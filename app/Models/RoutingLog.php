<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutingLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'phonenumber_id',
        'source_id',
        'company_id',
        'provider_id',
        'endpoint',
        'reason',
        'status',
        'message',
        'request_data',
        'response_data',
        'duration_ms',
        'attempt',
        'job_id',
    ];

    protected $casts = [
        'request_data' => 'array',
        'response_data' => 'array',
        'duration_ms' => 'integer',
        'attempt' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function phonenumber(): BelongsTo
    {
        return $this->belongsTo(Phonenumber::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function isError(): bool
    {
        return in_array(strtolower($this->status), ['error', 'failed', 'failure']);
    }
}
