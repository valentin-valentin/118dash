<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Email extends Model
{
    use HasFactory;

    protected $fillable = [
        'called',
        'to',
        'to_name',
        'to_email',
        'from',
        'from_name',
        'from_email',
        'need_reply',
        'last_message',
        'count_messages',
    ];

    protected $casts = [
        'need_reply' => 'boolean',
        'last_message' => 'datetime',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(EmailMessage::class);
    }

    public function phonenumbers(): HasMany
    {
        return $this->hasMany(EmailPhonenumber::class);
    }

    public function calls(): HasMany
    {
        return $this->hasMany(EmailCall::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(EmailRefund::class);
    }
}
