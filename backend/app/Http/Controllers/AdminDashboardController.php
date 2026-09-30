<?php

namespace App\Http\Controllers;

use App\Models\Manual;
use App\Models\ManualAccessLog;
use App\Models\QrCode;
use App\Models\QrScanLog;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $today = now()->startOfDay();
        $scanTrend = collect(range(6, 0))->map(function ($daysAgo) {
            $date = now()->subDays($daysAgo)->startOfDay();
            return ['date' => $date->toDateString(), 'scans' => QrScanLog::query()->whereBetween('created_at', [$date, $date->copy()->endOfDay()])->count()];
        })->values();

        $typeDistribution = DB::table('qr_types')
            ->leftJoin('qr_codes', 'qr_codes.qr_type_id', '=', 'qr_types.id')
            ->select('qr_types.name', DB::raw('COUNT(qr_codes.id) as value'))
            ->groupBy('qr_types.id', 'qr_types.name')
            ->orderBy('qr_types.display_order')
            ->get();

        $languageUsage = DB::table('languages')
            ->leftJoin('manuals', 'manuals.language_id', '=', 'languages.id')
            ->select('languages.name', DB::raw('COUNT(manuals.id) as value'))
            ->groupBy('languages.id', 'languages.name')
            ->orderByDesc('value')
            ->limit(8)
            ->get();

        $topManuals = Manual::query()->with(['manualType', 'vehicleVariant', 'engine', 'language'])
            ->withCount('accessLogs')
            ->orderByDesc('access_logs_count')
            ->limit(5)
            ->get();

        return response()->json([
            'cards' => [
                'total_vehicles' => \App\Models\VehicleVariant::query()->count(),
                'active_qr_codes' => QrCode::query()->available()->count(),
                'total_manuals' => Manual::query()->count(),
                'published_manuals' => Manual::query()->published()->count(),
                'total_customers' => Customer::query()->count(),
                'total_manual_views' => ManualAccessLog::query()->where('event', 'view')->count(),
                'today_scans' => QrScanLog::query()->where('created_at', '>=', $today)->count(),
            ],
            'charts' => [
                'scan_trend' => $scanTrend,
                'qr_type_distribution' => $typeDistribution,
                'language_usage' => $languageUsage,
                'top_manuals' => $topManuals,
            ],
            'recent_activity' => QrScanLog::query()->with(['qrCode', 'vehicleVariant', 'engine', 'language'])->latest()->limit(10)->get(),
        ]);
    }
}
