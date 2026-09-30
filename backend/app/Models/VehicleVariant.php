<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleVariant extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'description', 'status', 'display_order'];

    public function mappings(): HasMany
    {
        return $this->hasMany(VehicleEngineMapping::class);
    }

    public function engines(): BelongsToMany
    {
        return $this->belongsToMany(Engine::class, 'vehicle_engine_mappings');
    }

    public function manuals(): HasMany
    {
        return $this->hasMany(Manual::class);
    }

    public function qrCodes(): HasMany
    {
        return $this->hasMany(QrCode::class);
    }

    public function qrTypes(): BelongsToMany
    {
        return $this->belongsToMany(QrType::class, 'vehicle_variant_qr_type');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
