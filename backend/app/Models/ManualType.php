<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ManualType extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'status', 'display_order'];

    public function manuals(): HasMany
    {
        return $this->hasMany(Manual::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
