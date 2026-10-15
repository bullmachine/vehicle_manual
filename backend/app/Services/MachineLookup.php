<?php

namespace App\Services;

interface MachineLookup
{
    /**
     * Find a machine by its serial number (MSNO).
     *
     * @return array{msno: string, invoice: ?string, fert: string, description: ?string, plant: ?string}|null
     */
    public function find(string $serialNo): ?array;
}
