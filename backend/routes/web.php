<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'application' => config('portal.name'),
        'status' => 'ok',
    ]);
});
