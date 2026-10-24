<?php

namespace App\Http\Controllers;

use App\Models\Fert;
use App\Models\Language;
use App\Models\ManualType;
use App\Models\QrType;
use App\Services\AuditService;
use App\Services\FertMaster;
use App\Services\XlsxWriter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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
        if ($resource === 'ferts') {
            $query->with('qrTypes:id,code,name');
            if ($request->input('qr_type_id') === 'none') {
                $query->doesntHave('qrTypes');
            } elseif ($request->filled('qr_type_id')) {
                $query->whereHas('qrTypes', fn ($builder) => $builder->whereKey($request->integer('qr_type_id')));
            }
        }

        $query->orderBy('display_order')->orderByDesc('id');

        return response()->json($query->paginate($this->perPage($request, 25)));
    }

    public function store(string $resource, Request $request): JsonResponse
    {
        $model = $this->model($resource);
        $validated = $request->validate($this->rules($resource));
        $record = $model::create(Arr::except($validated, ['qr_type_ids']));
        $this->syncQrTypes($record, $validated);
        $this->audit->record('created', $resource, class_basename($model), $record->id, [], $validated);

        return response()->json(['data' => $record->fresh($this->relations($resource))], 201);
    }

    public function update(int|string $id, string $resource, Request $request): JsonResponse
    {
        $model = $this->model($resource);
        $record = $model::findOrFail($id);
        $validated = $request->validate($this->rules($resource, $record->id));
        $old = $record->getAttributes();
        if ($record instanceof Fert && array_key_exists('qr_type_ids', $validated)) {
            $old['qr_type_ids'] = $record->qrTypes()->pluck('qr_types.id')->all();
        }
        $record->update(Arr::except($validated, ['qr_type_ids']));
        $this->syncQrTypes($record, $validated);
        $this->audit->record('updated', $resource, class_basename($model), $record->id, $old, $validated);

        return response()->json(['data' => $record->fresh($this->relations($resource))]);
    }

    /**
     * Map a FERT to the QR types its machines may be opened from; an empty list opens it to every QR type.
     */
    private function syncQrTypes(Model $record, array $validated): void
    {
        if ($record instanceof Fert && array_key_exists('qr_type_ids', $validated)) {
            $record->qrTypes()->sync($validated['qr_type_ids'] ?? []);
        }
    }

    private function relations(string $resource): array
    {
        return $resource === 'ferts' ? ['qrTypes:id,code,name'] : [];
    }

    public function importFerts(Request $request, FertMaster $ferts): JsonResponse
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:json,csv,txt']]);
        $result = DB::transaction(fn () => $ferts->import($request->file('file')));
        $this->audit->record('imported', 'ferts', 'Fert', null, [], $result + ['file' => $request->file('file')->getClientOriginalName()]);

        return response()->json(['data' => $result]);
    }

    /**
     * Download the FERT master as an Excel file, honouring the list's search and status filters.
     */
    public function exportFerts(Request $request, XlsxWriter $xlsx): BinaryFileResponse
    {
        $query = Fert::query()
            ->with('qrTypes:id,name')
            ->withCount('manuals')
            ->addSelect(['machines_count' => DB::table('machines')->selectRaw('count(*)')->whereColumn('machines.fert', 'ferts.code')]);
        $search = trim((string) $request->input('q'));
        if ($search !== '') {
            $query->where(fn ($builder) => $builder->where('name', 'like', "%{$search}%")->orWhere('code', 'like', "%{$search}%"));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        $rows = $query->orderBy('code')->get()->map(fn (Fert $fert) => [
            $fert->code,
            $fert->name,
            $fert->description,
            ucfirst((string) $fert->status),
            $fert->qrTypes->pluck('name')->implode(', ') ?: 'All QR types',
            $fert->machines_count,
            $fert->manuals_count,
            $fert->updated_at?->format('d-m-Y H:i'),
        ]);

        $path = $xlsx->write('FERT master', ['FERT No', 'Name', 'Description', 'Status', 'QR types', 'Machines', 'Manuals mapped', 'Last updated'], $rows);
        $this->audit->record('exported', 'ferts', 'Fert', null, [], ['rows' => $rows->count(), 'q' => $search ?: null, 'status' => $request->input('status')]);

        return response()->download($path, 'fert-master-'.now()->format('Ymd-His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend();
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
                'qr_type_ids' => ['sometimes', 'nullable', 'array'],
                'qr_type_ids.*' => ['integer', 'distinct', 'exists:qr_types,id'],
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
