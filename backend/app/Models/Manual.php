<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Manual extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'manual_type_id',
        'vehicle_variant_id',
        'engine_id',
        'language_id',
        'status',
        'show_old_versions',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['show_old_versions' => 'boolean'];
    }

    public function manualType(): BelongsTo
    {
        return $this->belongsTo(ManualType::class);
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

    public function versions(): HasMany
    {
        return $this->hasMany(ManualVersion::class)->orderByDesc('published_at')->orderByDesc('id');
    }

    public function qrTypes(): BelongsToMany
    {
        return $this->belongsToMany(QrType::class, 'manual_qr_type');
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(ManualAccessLog::class);
    }

    public function publishedVersions(): HasMany
    {
        return $this->hasMany(ManualVersion::class)->where('status', 'published')->orderByDesc('published_at')->orderByDesc('id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published');
    }
}
