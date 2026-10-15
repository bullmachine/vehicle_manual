<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Manual;
use App\Models\ManualAccessLog;
use App\Models\QrCode;
use App\Models\QrScanLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AdminReportController extends Controller
{
    public function qrScans(Request $request): JsonResponse
    {
        $query = QrScanLog::query()->with(['qrCode.type', 'fert', 'language']);
        $this->dateFilters($query, $request);
        if ($request->filled('qr_type_id')) {
            $query->whereHas('qrCode', fn ($builder) => $builder->where('qr_type_id', $request->integer('qr_type_id')));
        }
        if ($request->filled('q')) {
            $term = $request->input('q');
            $query->where(fn ($builder) => $builder
                ->where('machine_serial_no', 'like', "%{$term}%")
                ->orWhereHas('qrCode', fn ($qr) => $qr->where('reference', 'like', "%{$term}%")));
        }
        $query->latest();

        return response()->json($query->paginate($this->perPage($request, 30)));
    }

    public function manualUsage(Request $request): JsonResponse
    {
        $query = Manual::query()->with(['manualType', 'ferts:id,code,name', 'language'])
            ->withCount(['accessLogs as total_views', 'accessLogs as unique_users' => fn ($builder) => $builder->distinct('customer_id')])
            ->when($request->filled('fert_id'), fn ($builder) => $builder->whereHas('ferts', fn ($fert) => $fert->whereKey($request->integer('fert_id'))))
            ->when($request->filled('language_id'), fn ($builder) => $builder->where('language_id', $request->integer('language_id')))
            ->orderByDesc('total_views');
        $manuals = $query->paginate($this->perPage($request, 30));
        $manuals->getCollection()->each(function ($manual) {
            $manual->last_viewed_at = ManualAccessLog::query()->where('manual_id', $manual->id)->max('created_at');
        });

        return response()->json($manuals);
    }

    public function customers(Request $request): JsonResponse
    {
        $query = Customer::query()->with(['sessions.qrCode', 'sessions.fert', 'sessions.language', 'accessLogs.manual']);
        if ($request->filled('q')) {
            $term = $request->input('q');
            $query->where(fn ($builder) => $builder->where('name', 'like', "%{$term}%")->orWhere('mobile', 'like', "%{$term}%"));
        }
        $query->latest('last_accessed_at');

        return response()->json($query->paginate($this->perPage($request, 30)));
    }

    public function exportQrScans(Request $request): StreamedResponse
    {
        $query = QrScanLog::query()->with(['qrCode.type', 'fert', 'language', 'session.customer']);
        $this->dateFilters($query, $request);
        $filename = 'qr-scan-report-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Date', 'Time', 'QR Code', 'QR Type', 'Machine Serial No', 'FERT', 'Machine', 'Language', 'Customer', 'IP', 'Device']);
            $query->latest()->chunk(500, function ($rows) use ($handle) {
                foreach ($rows as $row) {
                    fputcsv($handle, [$row->created_at?->toDateString(), $row->created_at?->format('H:i:s'), $row->qrCode?->reference, $row->qrCode?->type?->name, $row->machine_serial_no, $row->fert?->code, $row->fert?->name, $row->language?->name, $row->session?->customer?->name, $row->ip_address, $row->device]);
                }
            });
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function dateFilters($query, Request $request): void
    {
        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->date('from')->startOfDay());
        }
        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->date('to')->endOfDay());
        }
    }
}
