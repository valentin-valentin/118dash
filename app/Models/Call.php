<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Call extends Model
{
    use HasFactory;

    protected $fillable = [
        'voxnode_id',
        'caller',
        'called',
        'blacklist_id',
        'total_duration',
        'duration_agent',
        'duration_transfer',
        'called_at',
        'payout',
        'who_hangup',
        'agent_name',
        'brand_name',
        'brand_phonenumber',
        'carrier',
        'record_agent',
        'record_transfer',
        'support_sms_to_be_sent',
        'support_sms_to_be_sent_at',
        'support_sms_content',
        'support_sms_sent',
        'support_sms_clicked',
        'support_sms_clicked_at',
        'support_sms_filled',
        'support_sms_filled_at',
        'support_sms_emotion',
        'support_sms_review_wait_too_long',
        'support_sms_review_agent_need_to_improve',
        'support_sms_review_agent_bad',
        'support_sms_review_service_price',
        'support_sms_review_didnt_know_price',
        'support_sms_review_didnt_transfered',
        'support_sms_review_call_ended_before_transfer',
        'support_sms_review_free',
        'support_sms_request_recontact',
        'support_sms_email',
        'support_sms_recontact_at',
        'support_sms_recontacted',
        'support_sms_clicked_count',
        'info_sms_sent',
        'info_sms_to_be_sent_at',
        'info_sms_content',
        'info_sms_clicked',
        'info_sms_clicked_count',
        'info_sms_clicked_at',
        'ratings_locked_at',
        'ratings_review_started_at',
        'ratings_reviewed_at',
        'ratings_review_duration',
        'ratings_reviewer',
        'ratings_note',
        'ratings_not_rated',
        'ratings_not_rated_reason',
        'ratings_warning',
        'ratings_danger',
        'ratings_danger_reason',
        'ratings_info',
        'ratings_issue_microphone_quality',
        'ratings_issue_bad_lang',
        'ratings_issue_bad_understanding',
        'ratings_issue_bad_answers',
        'ratings_issue_not_happy',
        'ratings_issue_swearing',
        'ratings_issue_duration',
        'individual_search_id',
        'package_tracking_id',
        'agent_id',
        'callcenter_id',
        'phone_duration_agent',
        'phone_expected_min_duration_agent',
        'phone_expected_max_duration_agent',
        'phone_duration_pause',
        'phone_expected_min_duration_pause_agent',
        'phone_expected_max_duration_pause_agent',
        'phone_total_duration',
        'phone_expected_min_duration_total',
        'phone_expected_max_duration_total',
        'phone_answered_at',
        'phone_ended_at',
        'phone_agent_hangup',
        'phone_agent_hangup_reason',
        'phone_reported_by_agent',
        'phone_reported_by_agent_reason',
        'phone_cant_provide_service',
        'phone_cant_provide_service_reason',
        'phone_cant_provide_service_data',
        'phone_cant_provide_service_sms',
        'phone_cant_provide_service_email',
        'callback_for_offer',
        'callback_for_offer_api_response',
        'call_listened_by',
        'average_carrier_duration',
    ];

    protected $casts = [
        'called_at' => 'datetime',
        'support_sms_to_be_sent' => 'boolean',
        'support_sms_to_be_sent_at' => 'datetime',
        'support_sms_sent' => 'boolean',
        'support_sms_clicked' => 'boolean',
        'support_sms_clicked_at' => 'datetime',
        'support_sms_filled' => 'boolean',
        'support_sms_filled_at' => 'datetime',
        'support_sms_review_wait_too_long' => 'boolean',
        'support_sms_review_agent_need_to_improve' => 'boolean',
        'support_sms_review_agent_bad' => 'boolean',
        'support_sms_review_service_price' => 'boolean',
        'support_sms_review_didnt_know_price' => 'boolean',
        'support_sms_review_didnt_transfered' => 'boolean',
        'support_sms_review_call_ended_before_transfer' => 'boolean',
        'support_sms_request_recontact' => 'boolean',
        'support_sms_recontact_at' => 'datetime',
        'support_sms_recontacted' => 'boolean',
        'info_sms_sent' => 'boolean',
        'info_sms_to_be_sent_at' => 'datetime',
        'info_sms_clicked' => 'boolean',
        'info_sms_clicked_at' => 'datetime',
        'ratings_locked_at' => 'datetime',
        'ratings_review_started_at' => 'datetime',
        'ratings_reviewed_at' => 'datetime',
        'ratings_not_rated' => 'boolean',
        'ratings_warning' => 'boolean',
        'ratings_danger' => 'boolean',
        'ratings_info' => 'array',
        'ratings_issue_microphone_quality' => 'boolean',
        'ratings_issue_bad_lang' => 'boolean',
        'ratings_issue_bad_understanding' => 'boolean',
        'ratings_issue_bad_answers' => 'boolean',
        'ratings_issue_not_happy' => 'boolean',
        'ratings_issue_swearing' => 'boolean',
        'ratings_issue_duration' => 'boolean',
        'phone_answered_at' => 'datetime',
        'phone_ended_at' => 'datetime',
        'phone_agent_hangup' => 'boolean',
        'phone_reported_by_agent' => 'boolean',
        'phone_cant_provide_service' => 'boolean',
        'phone_cant_provide_service_data' => 'array',
        'phone_cant_provide_service_sms' => 'boolean',
        'phone_cant_provide_service_email' => 'boolean',
        'callback_for_offer' => 'boolean',
        'callback_for_offer_api_response' => 'array',
        'payout' => 'decimal:5',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function callcenter(): BelongsTo
    {
        return $this->belongsTo(Callcenter::class);
    }

    public function individualSearch(): BelongsTo
    {
        return $this->belongsTo(IndividualSearch::class);
    }

    public function packageTracking(): BelongsTo
    {
        return $this->belongsTo(PackageTracking::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(CallRating::class);
    }

    public function weblogs(): HasMany
    {
        return $this->hasMany(CallWeblog::class);
    }

    public function blacklist(): BelongsTo
    {
        return $this->belongsTo(Blacklist::class);
    }
}
