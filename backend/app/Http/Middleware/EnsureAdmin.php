<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isActiveAdmin()) {
            return response()->json(['message' => 'Administrator authentication is required.'], 401);
        }

        return $next($request);
    }
}
