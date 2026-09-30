<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ManualAccessLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'manual_id',
        'manual_version_id',
        'customer_id',
        'customer_session_id',
        'event',
        'page_number',
        'ip_address',
        'user_agent',
    ];

    public function manual(): BelongsTo
    {
        return $this->belongsTo(Manual::class);
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ManualVersion::class, 'manual_version_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CustomerSession::class, 'customer_session_id');
    }
}
