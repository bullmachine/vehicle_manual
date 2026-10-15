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

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
