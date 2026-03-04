<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmailPhonenumber extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_id',
        'phonenumber',
    ];

    public function email(): BelongsTo
    {
        return $this->belongsTo(Email::class);
    }
}
