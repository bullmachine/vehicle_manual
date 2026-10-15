<?php

namespace App\Http\Controllers;

use App\Services\PhoneNumberService;
use App\Services\PortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class PublicPortalController extends Controller
{
    public function __construct(private readonly PortalService $portal, private readonly PhoneNumberService $phones)
    {
    }

    public function qr(string $token, Request $request): JsonResponse
    {
        $qr = $this->portal->findQr($token);
        $this->portal->markScan($qr, $request);
        $context = $this->portal->context($qr);
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

    public function languages(): JsonResponse
    {
        return response()->json(\App\Models\Language::query()->active()->orderBy('display_order')->orderBy('name')->get(['id', 'code', 'name', 'native_name']));
    }

    /**
     * Identify the machine from its serial number and list the languages its manuals are available in.
     */
    public function machine(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_token' => ['required', 'string', 'min:32'],
            'machine_serial_no' => ['required', 'string', 'max:60'],
        ]);
        $this->portal->findQr($validated['qr_token']);
        ['machine' => $machine, 'fert' => $fert] = $this->portal->lookupMachine($validated['machine_serial_no']);

        return response()->json(['data' => [
            'machine' => $this->portal->machineData($machine, $fert),
            'languages' => $this->portal->languagesForFert($fert),
        ]]);
    }

    public function createSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'qr_token' => ['required', 'string', 'min:32'],
            'machine_serial_no' => ['required', 'string', 'max:60'],
            'language_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'mobile' => ['nullable', 'string', 'max:20'],
        ]);
        $qr = $this->portal->findQr($validated['qr_token']);
        ['machine' => $machine, 'fert' => $fert] = $this->portal->lookupMachine($validated['machine_serial_no']);
        $language = $this->portal->resolveLanguage($validated['language_id']);
        $mobile = filled($validated['mobile'] ?? null) ? $this->phones->normalize($validated['mobile']) : null;
        $session = $this->portal->createSession($qr, $machine, $fert, $language, trim($validated['name']), $mobile, $request);

        return $this->sessionResponse($request, $session['token'], $session['session']);
    }

    private function sessionResponse(Request $request, string $token, $session): JsonResponse
    {
        $response = response()->json(['data' => $this->portal->sessionData($session)]);
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
}
