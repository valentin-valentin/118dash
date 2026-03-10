<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Phonenumber extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'company_id',
        'provider_id',
        'only_source_id',
        'source_id',
        'phonenumber',
        'custom_routing_data',
        'telon_routing_id',
        'assigned_at',
        'display_expires_at',
        'real_expires_at',
        'last_assigned_at',
        'total_assignments',
        'total_calls',
        'total_duration',
        'current_endpoint',
        'last_routed_at',
        'routing_error',
        'routing_error_at',
        'will_be_deleted',
        'sip_registered',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'display_expires_at' => 'datetime',
        'real_expires_at' => 'datetime',
        'last_assigned_at' => 'datetime',
        'last_routed_at' => 'datetime',
        'routing_error_at' => 'datetime',
        'total_assignments' => 'integer',
        'total_calls' => 'integer',
        'total_duration' => 'integer',
        'will_be_deleted' => 'boolean',
        'sip_registered' => 'boolean',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function onlySource(): BelongsTo
    {
        return $this->belongsTo(Source::class, 'only_source_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    // Scope pour les numéros actifs (non supprimés)
    public function scopeActive($query)
    {
        return $query->whereNull('deleted_at');
    }

    // Scope pour les numéros supprimés uniquement
    public function scopeOnlyTrashed($query)
    {
        return $query->whereNotNull('deleted_at');
    }

    // Scope pour les numéros assignés
    public function scopeAssigned($query)
    {
        return $query->whereNotNull('assigned_at');
    }

    // Scope pour les numéros non assignés
    public function scopeUnassigned($query)
    {
        return $query->whereNull('assigned_at');
    }

    // Scope pour les numéros avec erreur de routing
    public function scopeWithRoutingError($query)
    {
        return $query->whereNotNull('routing_error');
    }
}
