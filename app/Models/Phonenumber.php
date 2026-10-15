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

    /**
     * Filtres de la page Numéros (aussi utilisés par l'Audit RSVA).
     * Clés : search, company_id, provider_id, only_source_id, source_id ('null' = aucune),
     * include_deleted / only_deleted ('yes'), assigned_status (assigned|unassigned),
     * routing_status (error|no_error).
     */
    public function scopeFilter($query, array $filters)
    {
        $filled = fn ($key) => isset($filters[$key]) && $filters[$key] !== '';

        // Inclure les numéros supprimés si demandé
        if (($filters['include_deleted'] ?? null) === 'yes') {
            $query->withTrashed();
        } elseif (($filters['only_deleted'] ?? null) === 'yes') {
            $query->onlyTrashed();
        }

        if ($filled('search')) {
            // Numéros stockés en +33... : "0891 24", "08.91.24" ou "+33 8 91" doivent aussi matcher
            $search = preg_replace('/[\s.\-]/', '', $filters['search']);
            $search = preg_replace('/^(\+33|0033|0)/', '+33', $search);
            $query->where('phonenumber', 'like', "%{$search}%");
        }

        if ($filled('company_id')) {
            $query->where('company_id', (int) $filters['company_id']);
        }

        if ($filled('provider_id')) {
            $query->where('provider_id', (int) $filters['provider_id']);
        }

        foreach (['only_source_id', 'source_id'] as $key) {
            if ($filled($key)) {
                $filters[$key] === 'null'
                    ? $query->whereNull($key)
                    : $query->where($key, (int) $filters[$key]);
            }
        }

        match ($filters['assigned_status'] ?? null) {
            'assigned' => $query->whereNotNull('assigned_at'),
            'unassigned' => $query->whereNull('assigned_at'),
            default => null,
        };

        match ($filters['routing_status'] ?? null) {
            'error' => $query->whereNotNull('routing_error'),
            'no_error' => $query->whereNull('routing_error'),
            default => null,
        };

        return $query;
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
