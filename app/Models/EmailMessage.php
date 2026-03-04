<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmailMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_id',
        'content',
        'from',
        'from_name',
        'from_email',
        'to',
        'to_name',
        'to_email',
        'received_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailMessageAttachment::class);
    }
}
