<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QrType extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'status', 'display_order'];

    public function qrCodes(): HasMany
    {
        return $this->hasMany(QrCode::class);
    }

    public function manuals(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Manual::class, 'manual_qr_type');
    }

    public function vehicleVariants(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(VehicleVariant::class, 'vehicle_variant_qr_type');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
