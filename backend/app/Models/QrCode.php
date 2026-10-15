<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrCode extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'token_hash',
        'token_ciphertext',
        'qr_type_id',
        'label',
        'vehicle_number',
        'chassis_number',
        'status',
        'expires_at',
        'token_version',
        'scan_count',
        'last_scanned_at',
        'created_by',
    ];

    protected $hidden = ['token_hash', 'token_ciphertext'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_scanned_at' => 'datetime',
        ];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(QrType::class, 'qr_type_id');
    }

    public function sessions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CustomerSession::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', 'active')->where(function (Builder $query) {
            $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }
}
