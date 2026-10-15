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
        'fert_id',
        'machine_serial_no',
        'language_id',
        'ip_address',
        'user_agent',
        'device',
    ];

    public function qrCode(): BelongsTo
    {
        return $this->belongsTo(QrCode::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CustomerSession::class, 'customer_session_id');
    }

    public function fert(): BelongsTo
    {
        return $this->belongsTo(Fert::class);
    }

    public function language(): BelongsTo
    {
        return $this->belongsTo(Language::class);
    }
}
