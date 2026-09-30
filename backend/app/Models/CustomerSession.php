<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'session_token_hash',
        'customer_id',
        'qr_code_id',
        'vehicle_variant_id',
        'engine_id',
        'language_id',
        'expires_at',
        'last_seen_at',
        'revoked_at',
        'ip_address',
        'user_agent',
    ];

    protected $hidden = ['session_token_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function qrCode(): BelongsTo
    {
        return $this->belongsTo(QrCode::class);
    }

    public function vehicleVariant(): BelongsTo
    {
        return $this->belongsTo(VehicleVariant::class);
    }

    public function engine(): BelongsTo
    {
        return $this->belongsTo(Engine::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
