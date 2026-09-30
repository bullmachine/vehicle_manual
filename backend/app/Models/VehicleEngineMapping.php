<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleEngineMapping extends Model
{
    use HasFactory;

    protected $fillable = ['vehicle_variant_id', 'engine_id'];

    public function vehicleVariant(): BelongsTo
    {
        return $this->belongsTo(VehicleVariant::class);
    }

    public function engine(): BelongsTo
    {
        return $this->belongsTo(Engine::class);
    }
}
