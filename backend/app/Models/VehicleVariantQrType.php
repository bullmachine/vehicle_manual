<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleVariantQrType extends Model
{
    use HasFactory;

    protected $table = 'vehicle_variant_qr_type';

    protected $fillable = ['vehicle_variant_id', 'qr_type_id'];

    public $timestamps = false;

    public function vehicleVariant(): BelongsTo
    {
        return $this->belongsTo(VehicleVariant::class);
    }

    public function qrType(): BelongsTo
    {
        return $this->belongsTo(QrType::class);
    }
}
