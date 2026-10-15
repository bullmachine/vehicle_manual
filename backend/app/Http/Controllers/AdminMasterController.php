<?php

namespace App\Http\Controllers;

use App\Models\Fert;
use App\Models\Language;
use App\Models\ManualType;
use App\Models\QrType;
use App\Services\AuditService;
use App\Services\FertMaster;
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
        if ($search !== '') {
            $query->where(fn ($builder) => $builder->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $query->orderBy('display_order')->orderByDesc('id');

        return response()->json($query->paginate($this->perPage($request, 25)));
    }

    public function store(string $resource, Request $request): JsonResponse
    {
        $model = $this->model($resource);
        $validated = $request->validate($this->rules($resource));
        $record = $model::create($validated);
        $this->audit->record('created', $resource, class_basename($model), $record->id, [], $validated);

        return response()->json(['data' => $record->fresh()], 201);
    }

    public function update(int|string $id, string $resource, Request $request): JsonResponse
    {
        $model = $this->model($resource);
        $record = $model::findOrFail($id);
        $validated = $request->validate($this->rules($resource, $record->id));
        $old = $record->getAttributes();
        $record->update($validated);
        $this->audit->record('updated', $resource, class_basename($model), $record->id, $old, $validated);

        return response()->json(['data' => $record->fresh()]);
    }

    public function importFerts(Request $request, FertMaster $ferts): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:json,csv,txt']]);
        $result = $ferts->import($request->file('file'));
        $this->audit->record('imported', 'ferts', 'Fert', null, [], $result + ['file' => $request->file('file')->getClientOriginalName()]);

        return response()->json(['data' => $result]);
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
            'ferts' => new Fert(),
            'languages' => new Language(),
            'manual-types' => new ManualType(),
            'qr-types' => new QrType(),
            default => abort(404),
        };
    }

    private function rules(string $resource, int|string|null $ignore = null): array
    {
        $unique = fn (string $table, string $column) => Rule::unique($table, $column)->ignore($ignore);

        return match ($resource) {
            'ferts' => [
                'code' => ['required', 'string', 'max:40', $unique('ferts', 'code')],
                'name' => ['required', 'string', 'max:160'],
                'description' => ['nullable', 'string', 'max:2000'],
                'status' => ['required', 'in:active,inactive'],
                'display_order' => ['nullable', 'integer', 'min:0'],
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
