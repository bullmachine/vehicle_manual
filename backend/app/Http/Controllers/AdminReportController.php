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
        $query = QrScanLog::query()->with(['qrCode.type', 'vehicleVariant', 'engine', 'language']);
        $this->dateFilters($query, $request);
        if ($request->filled('qr_type_id')) {
            $query->whereHas('qrCode', fn ($builder) => $builder->where('qr_type_id', $request->integer('qr_type_id')));
        }
        if ($request->filled('q')) {
            $query->whereHas('qrCode', fn ($builder) => $builder->where('reference', 'like', '%'.$request->input('q').'%'));
        }
        $query->latest();

        return response()->json($query->paginate((int) $request->input('per_page', 30)));
    }

    public function manualUsage(Request $request): JsonResponse
    {
        $query = Manual::query()->with(['manualType', 'vehicleVariant', 'engine', 'language'])
            ->withCount(['accessLogs as total_views', 'accessLogs as unique_users' => fn ($builder) => $builder->distinct('customer_id')])
            ->when($request->filled('vehicle_variant_id'), fn ($builder) => $builder->where('vehicle_variant_id', $request->integer('vehicle_variant_id')))
            ->when($request->filled('engine_id'), fn ($builder) => $builder->where('engine_id', $request->integer('engine_id')))
            ->when($request->filled('language_id'), fn ($builder) => $builder->where('language_id', $request->integer('language_id')))
            ->orderByDesc('total_views');
        $manuals = $query->paginate((int) $request->input('per_page', 30));
        $manuals->getCollection()->each(function ($manual) {
            $manual->last_viewed_at = ManualAccessLog::query()->where('manual_id', $manual->id)->max('created_at');
        });

        return response()->json($manuals);
    }

    public function customers(Request $request): JsonResponse
    {
        $query = Customer::query()->with(['sessions.qrCode', 'sessions.vehicleVariant', 'sessions.engine', 'sessions.language', 'accessLogs.manual']);
        if ($request->filled('q')) {
            $term = $request->input('q');
            $query->where(fn ($builder) => $builder->where('name', 'like', "%{$term}%")->orWhere('mobile', 'like', "%{$term}%"));
        }
        $query->latest('last_accessed_at');

        return response()->json($query->paginate((int) $request->input('per_page', 30)));
    }

    public function exportQrScans(Request $request): StreamedResponse
    {
        $query = QrScanLog::query()->with(['qrCode', 'vehicleVariant', 'engine', 'language']);
        $this->dateFilters($query, $request);
        $filename = 'qr-scan-report-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Date', 'Time', 'QR Code', 'QR Type', 'Vehicle', 'Engine', 'Customer', 'IP', 'Device']);
            $query->latest()->chunk(500, function ($rows) use ($handle) {
                foreach ($rows as $row) {
                    fputcsv($handle, [$row->created_at?->toDateString(), $row->created_at?->toFormat('H:i:s'), $row->qrCode?->reference, $row->qrCode?->type?->name, $row->vehicleVariant?->name, $row->engine?->name, '', $row->ip_address, $row->device]);
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
