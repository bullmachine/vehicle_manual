<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Page size from the request, kept within 1..$max so one request cannot load a whole table.
     */
    protected function perPage(Request $request, int $default = 25, int $max = 100): int
    {
        return min(max((int) $request->input('per_page', $default), 1), $max);
    }
}
