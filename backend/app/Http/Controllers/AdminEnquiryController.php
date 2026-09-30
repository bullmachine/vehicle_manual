<?php

namespace App\Http\Controllers;

use App\Models\SupportEnquiry;
use App\Services\AuditService;
use App\Services\PhoneNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminEnquiryController extends Controller
{
    public function __construct(private readonly AuditService $audit, private readonly PhoneNumberService $phones)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $enquiries = SupportEnquiry::query()
            ->with(['qrCode:id,reference,label', 'session.vehicleVariant:id,name', 'session.engine:id,name', 'session.language:id,name'])
            ->status($request->input('status'))
            ->search($request->input('q'))
            ->latest('created_at')
            ->paginate((int) $request->input('per_page', 30));

        $enquiries->setCollection($enquiries->getCollection()->map(fn (SupportEnquiry $enquiry) => [
            'id' => $enquiry->id,
            'name' => $enquiry->name,
            'mobile' => $enquiry->mobile,
            'mobile_display' => $this->phones->formatForDisplay($enquiry->mobile),
            'mobile_region' => $this->phones->regionFor($enquiry->mobile),
            'email' => $enquiry->email,
            'subject' => $enquiry->subject,
            'message' => $enquiry->message,
            'status' => $enquiry->status,
            'source' => $enquiry->source,
            'created_at' => $enquiry->created_at,
            'resolved_at' => $enquiry->resolved_at,
            'qr' => $enquiry->qrCode ? [
                'id' => $enquiry->qrCode->id,
                'reference' => $enquiry->qrCode->reference,
                'label' => $enquiry->qrCode->label,
            ] : null,
            'vehicle' => $this->contextLabel($enquiry),
        ]));

        return response()->json($enquiries);
    }

    private function contextLabel(SupportEnquiry $enquiry): ?string
    {
        $session = $enquiry->session;

        if (! $session) {
            return null;
        }

        return collect([
            $session->vehicleVariant?->name,
            $session->engine?->name,
            $session->language?->name,
        ])->filter()->implode(' · ');
    }

    public function update(Request $request, SupportEnquiry $enquiry): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:'.implode(',', SupportEnquiry::STATUSES)],
        ]);

        $old = $enquiry->only(['status', 'resolved_at']);
        $enquiry->status = $validated['status'];
        $enquiry->resolved_at = $validated['status'] === SupportEnquiry::STATUS_CLOSED ? now() : null;
        $enquiry->save();

        $this->audit->record('updated', 'support_enquiries', $enquiry::class, $enquiry->id, $old, $enquiry->only(['status', 'resolved_at']));

        return response()->json(['data' => [
            'id' => $enquiry->id,
            'status' => $enquiry->status,
            'resolved_at' => $enquiry->resolved_at,
        ]]);
    }

    public function destroy(SupportEnquiry $enquiry): JsonResponse
    {
        $recordId = $enquiry->id;
        $snapshot = $enquiry->only(['name', 'mobile', 'email', 'subject', 'status']);
        $enquiry->delete();

        $this->audit->record('deleted', 'support_enquiries', SupportEnquiry::class, $recordId, $snapshot);

        return response()->json(['message' => 'Support enquiry deleted.']);
    }
}
