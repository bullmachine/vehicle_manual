<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Machine record synced from SAP (INVOICE, FERT, DESCRIPTION, PLANT, MSNO, DELIVERY_DATE).
 */
class Machine extends Model
{
    use HasFactory;

    protected $fillable = ['msno', 'fert', 'description', 'invoice', 'plant', 'delivery_date', 'source', 'synced_at'];

    /**
     * Canonical form of a serial number, used for both SAP data and what customers type:
     * upper case, no whitespace of any kind (including non-breaking spaces), and no
     * stray dots or dashes at either end.
     */
    public static function normalizeSerialNo(string $serialNo): string
    {
        $serial = preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', '', $serialNo) ?? '';

        return strtoupper(trim($serial, '.-_'));
    }

    protected function casts(): array
    {
        return [
            'delivery_date' => 'date:Y-m-d',
            'synced_at' => 'datetime',
        ];
    }
}
