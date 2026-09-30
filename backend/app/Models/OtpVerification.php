<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OtpVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'mobile',
        'otp_hash',
        'expires_at',
        'attempts',
        'max_attempts',
        'sent_at',
        'verified_at',
        'invalidated_at',
        'request_ip',
        'user_agent',
    ];

    protected $hidden = ['otp_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'verified_at' => 'datetime',
            'invalidated_at' => 'datetime',
        ];
    }
}
