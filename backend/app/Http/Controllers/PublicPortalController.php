<?php

namespace App\Http\Controllers;

use App\Services\OtpService;
use App\Services\PhoneNumberService;
use App\Services\PortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;

class PublicPortalController extends Controller
{
    public function __construct(private readonly PortalService $portal, private readonly OtpService $otp, private readonly PhoneNumberService $phones)
    {
    }

    public function qr(string $token, Request $request): JsonResponse
    {
        $qr = $this->portal->findQr($token);
        $this->portal->markScan($qr, $request);
        $context = $this->portal->context($qr);
        $context['otp_enabled'] = $this->otp->enabled();
        $context['otp_length'] = $this->otp->length();
        $context['default_phone_region'] = $this->phones->defaultRegion();

        return response()->json($context);
    }

    /**
     * Country options for the public mobile field, honouring the configured allowlist.
     */
    public function phoneCountries(): JsonResponse
    {
        return response()->json(['data' => [
            'default' => $this->phones->defaultRegion(),
            'countries' => $this->phones->countries(),
        ]]);
    }

    public function vehicleVariants(Request $request): JsonResponse
    {
        return response()->json($this->portal->vehicleOptionsForType($request->filled('qr_type_id') ? $request->integer('qr_type_id') : null));
    }

    public function engines(Request $request): JsonResponse
    {
        $query = \App\Models\Engine::query()->active()->orderBy('name');
        if ($request->filled('vehicle_variant_id')) {
            $query->whereHas('mappings', fn ($mapping) => $mapping->where('vehicle_variant_id', $request->integer('vehicle_variant_id')));
        }

        return response()->json($query->get(['id', 'code', 'name']));
    }

    public function languages(): JsonResponse
    {
        return response()->json(\App\Models\Language::query()->active()->orderBy('display_order')->orderBy('name')->get(['id', 'code', 'name', 'native_name']));
    }

    public function requestOtp(Request $request): JsonResponse
    {
        if (! $this->otp->enabled()) {
            throw ValidationException::withMessages(['otp' => 'OTP verification is currently disabled.']);
        }

        $validated = $request->validate([
            'qr_token' => ['required', 'string', 'min:32'],
            'name' => ['required', 'string', 'max:120'],
            'mobile' => ['required', 'string', 'max:20'],
            'vehicle_variant_id' => ['nullable', 'integer'],
            'engine_id' => ['nullable', 'integer'],
            'language_id' => ['nullable', 'integer'],
        ]);
        $qr = $this->portal->findQr($validated['qr_token']);
        $selection = $this->portal->resolveSelection($qr, $validated);
        $mobile = $this->phones->normalize($validated['mobile']);

        return response()->json($this->otp->request($mobile, $request));
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_token' => ['required', 'string', 'min:32'],
            'name' => ['required', 'string', 'max:120'],
            'mobile' => ['required', 'string', 'max:20'],
            'otp' => ['required', 'digits:'.$this->otp->length()],
            'vehicle_variant_id' => ['nullable', 'integer'],
            'engine_id' => ['nullable', 'integer'],
            'language_id' => ['nullable', 'integer'],
        ]);
        $qr = $this->portal->findQr($validated['qr_token']);
        $selection = $this->portal->resolveSelection($qr, $validated);
        $mobile = $this->phones->normalize($validated['mobile']);
        $this->otp->verify($mobile, $validated['otp']);
        $session = $this->portal->createSession($qr, $selection, $validated['name'], $mobile, $request);
        return $this->sessionResponse($request, $session['token'], $session['session']);
    }

    public function createSession(Request $request): JsonResponse
    {
        if ($this->otp->enabled()) {
            throw ValidationException::withMessages(['otp' => 'OTP verification is required.']);
        }

        $validated = $request->validate([
            'qr_token' => ['required', 'string', 'min:32'],
            'name' => ['required', 'string', 'max:120'],
            'mobile' => ['required', 'string', 'max:20'],
            'vehicle_variant_id' => ['nullable', 'integer'],
            'engine_id' => ['nullable', 'integer'],
            'language_id' => ['nullable', 'integer'],
        ]);
        $qr = $this->portal->findQr($validated['qr_token']);
        $selection = $this->portal->resolveSelection($qr, $validated);
        $mobile = $this->phones->normalize($validated['mobile']);
        $session = $this->portal->createSession($qr, $selection, $validated['name'], $mobile, $request);
        return $this->sessionResponse($request, $session['token'], $session['session']);
    }

    private function sessionResponse(Request $request, string $token, $session): JsonResponse
    {
        $response = response()->json(['data' => $this->sessionData($session)]);
        $response->headers->setCookie(Cookie::make(
            'customer_session',
            $token,
            (int) config('portal.customer_session_minutes', 120),
            '/',
            null,
            (bool) config('session.secure', $request->isSecure()),
            true,
            false,
            'Lax'
        ));

        return $response;
    }

    private function sessionData($session): array
    {
        return [
            'id' => $session->id,
            'customer' => $session->customer->only(['id', 'name', 'mobile']),
            'vehicle_variant' => $session->vehicleVariant->only(['id', 'name']),
            'engine' => $session->engine->only(['id', 'name']),
            'language' => $session->language->only(['id', 'name']),
            'expires_at' => $session->expires_at->toIso8601String(),
        ];
    }
}
