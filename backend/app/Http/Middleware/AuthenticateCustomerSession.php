<?php

namespace App\Http\Middleware;

use App\Models\CustomerSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateCustomerSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->cookie('customer_session') ?: $request->header('X-Customer-Session');

        if (! is_string($token) || strlen($token) < 32) {
            return response()->json(['code' => 'SESSION_EXPIRED', 'message' => 'Your session has expired.'], 401);
        }

        $session = CustomerSession::query()
            ->with(['customer', 'qrCode', 'vehicleVariant', 'engine', 'language'])
            ->where('session_token_hash', hash('sha256', $token))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $session || $session->qrCode->status !== 'active' || ($session->qrCode->expires_at && $session->qrCode->expires_at->isPast())) {
            return response()->json(['code' => 'SESSION_EXPIRED', 'message' => 'Your session has expired.'], 401);
        }

        $session->forceFill(['last_seen_at' => now()])->save();
        $request->attributes->set('customer_session', $session);

        return $next($request);
    }
}
