<?php

namespace App\Http\Controllers;

use App\Models\CustomerSession;
use App\Models\QrCode;
use App\Models\SupportEnquiry;
use App\Services\PhoneNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicSupportController extends Controller
{
    public function __construct(private readonly PhoneNumberService $phones)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'mobile' => ['required', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:160'],
            'subject' => ['required', 'string', 'max:160'],
            'message' => ['required', 'string', 'min:10', 'max:4000'],
            'qr_token' => ['nullable', 'string', 'max:80'],
            'customer_session_id' => ['nullable', 'integer', 'exists:customer_sessions,id'],
        ]);

        $session = isset($validated['customer_session_id'])
            ? CustomerSession::query()->whereKey($validated['customer_session_id'])->whereNull('revoked_at')->first()
            : null;

        $enquiry = SupportEnquiry::create([
            'name' => $validated['name'],
            'mobile' => $this->phones->normalize($validated['mobile']),
            'email' => $validated['email'] ?? null,
            'subject' => $validated['subject'],
            'message' => $validated['message'],
            'status' => SupportEnquiry::STATUS_NEW,
            'source' => 'portal_footer',
            'qr_code_id' => $this->resolveQrId($validated['qr_token'] ?? null),
            'customer_session_id' => $session?->id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return response()->json([
            'data' => $enquiry->only(['id', 'name', 'mobile', 'email', 'subject', 'status', 'created_at']),
        ], 201);
    }

    /**
     * Resolves the scanned token to a QR id so the secret token is never stored in plaintext.
     * An unrecognised token is ignored rather than rejected, so the enquiry still reaches support.
     */
    private function resolveQrId(?string $token): ?int
    {
        if (! $token) {
            return null;
        }

        return QrCode::query()->where('token_hash', hash('sha256', $token))->value('id');
    }
}
