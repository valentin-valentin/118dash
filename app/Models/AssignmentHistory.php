<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentHistory extends Model
{
    use HasFactory;

    protected $table = 'assignment_history';

    protected $fillable = [
        'phonenumber_id',
        'source_id',
        'company_id',
        'provider_id',
        'start_at',
        'end_at',
        'endpoint',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
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
}
