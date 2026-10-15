<?php

namespace App\Services;

use App\Models\Machine;

/**
 * Looks machines up in the local machines table, which `php artisan machines:sync`
 * fills from the SAP machine API (or from the dummy JSON file).
 */
class DatabaseMachineLookup implements MachineLookup
{
    public function find(string $serialNo): ?array
    {
        $machine = Machine::query()->where('msno', $serialNo)->first();

        if (! $machine) {
            return null;
        }

        return [
            'msno' => $machine->msno,
            'invoice' => $machine->invoice,
            'fert' => $machine->fert,
            'description' => $machine->description,
            'plant' => $machine->plant,
        ];
    }
}
