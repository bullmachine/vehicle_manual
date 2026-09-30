<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrScanLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'qr_code_id',
        'customer_session_id',
        'vehicle_variant_id',
        'engine_id',
        'language_id',
        'ip_address',
        'user_agent',
        'device',
    ];

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
