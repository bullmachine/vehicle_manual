<?php

namespace App\Http\Controllers;

use App\Services\ManualAccessService;
use App\Services\PdfPageRenderer;
use App\Services\PortalService;
use App\Models\ManualFeedback;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerPortalController extends Controller
{
    public function __construct(private readonly PortalService $portal, private readonly ManualAccessService $access, private readonly PdfPageRenderer $renderer)
    {
    }

    public function session(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->sessionData($request->attributes->get('customer_session'))]);
    }

    public function manuals(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->portal->manuals($request->attributes->get('customer_session'))]);
    }

    public function manual(Request $request, int $manualId, ?int $versionId = null): JsonResponse
    {
        $session = $request->attributes->get('customer_session');
        $versionId = $versionId ?: $request->integer('version_id') ?: null;
        [$manual, $version] = $this->portal->authorizeManual($session, $manualId, $versionId);
        $this->access->record($session, $manual, $version, 'view', $request);

        return response()->json(['data' => [
            'id' => $manual->id,
            'title' => $manual->title,
            'type' => $manual->manualType?->name,
            'vehicle' => $manual->vehicleVariant?->name,
            'engine' => $manual->engine?->name,
            'language' => $manual->language?->name,
            'version' => $version->only(['id', 'version', 'page_count', 'published_at', 'effective_date']),
        ]]);
    }

    public function page(Request $request, int $manualId, int $page, ?int $versionId = null)
    {
        $session = $request->attributes->get('customer_session');
        $versionId = $versionId ?: $request->integer('version_id') ?: null;
        [$manual, $version] = $this->portal->authorizeManual($session, $manualId, $versionId);
        abort_if($page < 1 || ($version->page_count > 0 && $page > $version->page_count), 404);
        $this->access->record($session, $manual, $version, 'page_view', $request, $page);
        $watermark = implode(' · ', [
            $session->customer->name,
            $session->customer->mobile,
            'Session '.substr($session->session_token_hash, 0, 8),
        ]);

        return $this->renderer->render($version, $page, $watermark);
    }

    public function feedback(Request $request, int $manualId): JsonResponse
    {
        $validated = $request->validate([
            'rating' => ['nullable', 'in:yes,no'],
            'issue_type' => ['nullable', 'in:wrong_language,incorrect_information,missing_page,unable_to_read,other'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        $session = $request->attributes->get('customer_session');
        [$manual] = $this->portal->authorizeManual($session, $manualId);
        ManualFeedback::create([
            'manual_id' => $manual->id,
            'customer_session_id' => $session->id,
            'rating' => $validated['rating'] ?? null,
            'issue_type' => $validated['issue_type'] ?? null,
            'comment' => $validated['comment'] ?? null,
        ]);

        return response()->json(['message' => 'Thank you for your feedback.']);
    }

    public function search(Request $request, int $manualId): JsonResponse
    {
        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $session = $request->attributes->get('customer_session');
        [$manual, $version] = $this->portal->authorizeManual($session, $manualId, $request->integer('version_id') ?: null);
        $text = trim((string) ($version->search_text ?? ''));
        $matches = $text !== '' && stripos($text, $validated['q']) !== false;

        return response()->json(['data' => ['available' => $text !== '', 'matches' => $matches ? [$validated['q']] : []]]);
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
