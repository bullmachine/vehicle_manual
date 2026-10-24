<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * FERT material number master. A machine serial number resolves to one FERT,
 * and manuals are mapped to the FERTs they apply to.
 */
class Fert extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'description', 'status', 'display_order'];

    public function manuals(): BelongsToMany
    {
        return $this->belongsToMany(Manual::class, 'fert_manual');
    }

    public function qrTypes(): BelongsToMany
    {
        return $this->belongsToMany(QrType::class, 'fert_qr_type');
    }

    /**
     * Whether a machine of this FERT may be opened from a QR code of the given type.
     * A FERT that is not mapped to any QR type is open to every QR code.
     */
    public function allowsQrType(?int $qrTypeId): bool
    {
        $mapped = $this->qrTypes()->pluck('qr_types.id');

        return $mapped->isEmpty() || $mapped->contains($qrTypeId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
