<?php

namespace App\Http\Controllers;

use App\Models\Engine;
use App\Models\Language;
use App\Models\ManualType;
use App\Models\QrType;
use App\Models\VehicleEngineMapping;
use App\Models\VehicleVariant;
use App\Models\VehicleVariantQrType;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminMasterController extends Controller
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(string $resource, Request $request): JsonResponse
    {
        $query = $this->model($resource)->newQuery();
        $search = trim((string) $request->input('q'));
        if ($search !== '' && ! in_array($resource, ['mappings', 'vehicle-qr-types'], true)) {
            $query->where(fn ($builder) => $builder->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        if ($resource === 'mappings') {
            $query->with(['vehicleVariant', 'engine']);
        }

        if ($resource === 'vehicle-qr-types') {
            $query->with(['vehicleVariant', 'qrType']);
        }

        $query->orderBy($this->orderColumn($resource))->orderByDesc('id');

        return response()->json($query->paginate((int) $request->input('per_page', 25)));
    }

    public function store(string $resource, Request $request): JsonResponse
    {
        $model = $this->model($resource);
        $validated = $request->validate($this->rules($resource));
        $this->guardDuplicate($resource, $validated);
        $record = $model::create($validated);
        $this->audit->record('created', $resource, class_basename($model), $record->id, [], $validated);

        return response()->json(['data' => $record->fresh($this->relations($resource))], 201);
    }

    public function update(int|string $id, string $resource, Request $request): JsonResponse
    {
        $model = $this->model($resource);
        $record = $model::findOrFail($id);
        $validated = $request->validate($this->rules($resource, $record->id));
        $this->guardDuplicate($resource, $validated, (int) $record->id);
        $old = $record->getAttributes();
        $record->update($validated);
        $this->audit->record('updated', $resource, class_basename($model), $record->id, $old, $validated);

        return response()->json(['data' => $record->fresh($this->relations($resource))]);
    }

    /**
     * Resources whose rows are guarded by restrictOnDelete foreign keys and
     * therefore fall back to being hidden (status=inactive) when something
     * still references them instead of failing with a foreign-key error.
     */
    private const ARCHIVABLE_RESOURCES = [
        'vehicles',
        'engines',
        'languages',
        'manual-types',
        'qr-types',
    ];

    public function destroy(int|string $id, string $resource): JsonResponse
    {
        $model = $this->model($resource);
        $record = $model::findOrFail($id);
        $old = $record->getAttributes();
        try {
            $record->delete();
        } catch (\Throwable $exception) {
            if (! in_array($resource, self::ARCHIVABLE_RESOURCES, true)) {
                throw ValidationException::withMessages(['record' => 'This master is in use and cannot be deleted.']);
            }

            $record->update(['status' => 'inactive']);
            $this->audit->record('updated', $resource, class_basename($model), $record->id, $old, $record->getAttributes());

            return response()->json(['message' => 'Deleted.']);
        }
        $this->audit->record('deleted', $resource, class_basename($model), $id, $old, []);

        return response()->json(['message' => 'Deleted.']);
    }

    public function model(string $resource): Model
    {
        return match ($resource) {
            'vehicles' => new VehicleVariant(),
            'engines' => new Engine(),
            'mappings' => new VehicleEngineMapping(),
            'vehicle-qr-types' => new VehicleVariantQrType(),
            'languages' => new Language(),
            'manual-types' => new ManualType(),
            'qr-types' => new QrType(),
            default => abort(404),
        };
    }

    private function guardDuplicate(string $resource, array $validated, ?int $ignore = null): void
    {
        if ($resource !== 'vehicle-qr-types') {
            return;
        }

        $exists = VehicleVariantQrType::query()
            ->where('vehicle_variant_id', $validated['vehicle_variant_id'])
            ->where('qr_type_id', $validated['qr_type_id'])
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['vehicle_variant_id' => 'This vehicle variant is already mapped to that QR type.']);
        }
    }

    private function relations(string $resource): array
    {
        return match ($resource) {
            'mappings' => ['vehicleVariant', 'engine'],
            'vehicle-qr-types' => ['vehicleVariant', 'qrType'],
            default => [],
        };
    }

    private function orderColumn(string $resource): string
    {
        return match ($resource) {
            'mappings', 'vehicle-qr-types' => 'id',
            'engines' => 'name',
            default => 'display_order',
        };
    }

    private function rules(string $resource, int|string|null $ignore = null): array
    {
        $unique = fn (string $table, string $column) => Rule::unique($table, $column)->ignore($ignore);

        return match ($resource) {
            'vehicles' => [
                'code' => ['required', 'string', 'max:50', $unique('vehicle_variants', 'code')],
                'name' => ['required', 'string', 'max:120'],
                'description' => ['nullable', 'string', 'max:2000'],
                'status' => ['required', 'in:active,inactive'],
                'display_order' => ['nullable', 'integer', 'min:0'],
            ],
            'engines' => [
                'code' => ['required', 'string', 'max:50', $unique('engines', 'code')],
                'name' => ['required', 'string', 'max:120'],
                'description' => ['nullable', 'string', 'max:2000'],
                'status' => ['required', 'in:active,inactive'],
            ],
            'mappings' => [
                'vehicle_variant_id' => ['required', 'integer', 'exists:vehicle_variants,id'],
                'engine_id' => ['required', 'integer', 'exists:engines,id'],
            ],
            'vehicle-qr-types' => [
                'vehicle_variant_id' => ['required', 'integer', Rule::exists('vehicle_variants', 'id')->where('status', 'active')],
                'qr_type_id' => ['required', 'integer', Rule::exists('qr_types', 'id')->where('status', 'active')],
            ],
            'languages' => [
                'code' => ['required', 'string', 'max:10', $unique('languages', 'code')],
                'name' => ['required', 'string', 'max:80'],
                'native_name' => ['nullable', 'string', 'max:80'],
                'status' => ['required', 'in:active,inactive'],
                'display_order' => ['nullable', 'integer', 'min:0'],
            ],
            'manual-types' => [
                'code' => ['required', 'string', 'max:50', $unique('manual_types', 'code')],
                'name' => ['required', 'string', 'max:120'],
                'status' => ['required', 'in:active,inactive'],
                'display_order' => ['nullable', 'integer', 'min:0'],
            ],
            'qr-types' => [
                'code' => ['required', 'string', 'max:50', $unique('qr_types', 'code')],
                'name' => ['required', 'string', 'max:120'],
                'status' => ['required', 'in:active,inactive'],
                'display_order' => ['nullable', 'integer', 'min:0'],
            ],
            default => abort(404),
        };
    }
}
