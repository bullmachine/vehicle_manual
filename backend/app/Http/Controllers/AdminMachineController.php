<?php

namespace App\Http\Controllers;

use App\Models\Fert;
use App\Models\Machine;
use App\Services\AuditService;
use App\Services\MachineSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Machines synced from SAP: browse them and trigger a sync from the admin panel.
 */
class AdminMachineController extends Controller
{
    public function __construct(private readonly MachineSync $sync, private readonly AuditService $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Machine::query();

        if ($request->filled('q')) {
            $term = '%'.addcslashes(trim((string) $request->input('q')), '%_\\').'%';
            $query->where(fn ($builder) => $builder
                ->where('msno', 'like', $term)
                ->orWhere('fert', 'like', $term)
                ->orWhere('description', 'like', $term)
                ->orWhere('invoice', 'like', $term));
        }
        foreach (['plant', 'fert', 'source'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        $machines = $query->orderByDesc('delivery_date')->orderBy('msno')
            ->paginate($this->perPage($request, 50, 200));

        $fertNames = Fert::query()->whereIn('code', $machines->getCollection()->pluck('fert')->unique())->pluck('name', 'code');
        $machines->getCollection()->each(fn (Machine $machine) => $machine->setAttribute('fert_name', $fertNames[$machine->fert] ?? null));

        $response = $machines->toArray();
        $response['summary'] = [
            'total' => Machine::query()->count(),
            'from_sap' => Machine::query()->where('source', 'sap')->count(),
            'plants' => Machine::query()->whereNotNull('plant')->distinct()->orderBy('plant')->pluck('plant'),
            'configured_plants' => config('services.sap_machines.plants'),
            'sap_configured' => filled(config('services.sap_machines.url')),
            'last_sync' => $this->sync->lastRun(),
        ];

        return response()->json($response);
    }

    public function sync(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after_or_equal:from'],
            'plants' => ['nullable', 'array'],
            'plants.*' => ['string', 'max:20', 'regex:/^[A-Za-z0-9]+$/'],
        ]);
        $from = Carbon::parse($validated['from'])->startOfDay();
        $to = Carbon::parse($validated['to'])->startOfDay();

        if ($from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['to' => 'Sync at most one year at a time from the admin panel. Use `php artisan machines:sync` for longer ranges.']);
        }

        $plants = ! empty($validated['plants']) ? $validated['plants'] : config('services.sap_machines.plants');
        set_time_limit(300);

        try {
            $result = $this->sync->syncPlants($plants, $from, $to, 31, $request->user()->email);
        } catch (\RuntimeException $exception) {
            throw ValidationException::withMessages(['sync' => $exception->getMessage()]);
        }

        $this->audit->record('synced', 'machines', Machine::class, null, [], $result + ['plants' => $plants, 'from' => $from->toDateString(), 'to' => $to->toDateString()]);

        return response()->json(['data' => $result, 'last_sync' => $this->sync->lastRun()]);
    }
}
