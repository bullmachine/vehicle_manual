<?php

namespace App\Http\Controllers;

use App\Models\QrCode;
use App\Services\AuditService;
use App\Services\PortalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminQrController extends Controller
{
    public function __construct(private readonly AuditService $audit, private readonly PortalService $portal)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = QrCode::query()->with('type');
        if ($request->filled('q')) {
            $query->where(function ($builder) use ($request) {
                $term = $request->input('q');
                $builder->where('reference', 'like', "%{$term}%")->orWhere('label', 'like', "%{$term}%")->orWhere('vehicle_number', 'like', "%{$term}%")->orWhere('chassis_number', 'like', "%{$term}%");
            });
        }
        foreach (['status', 'qr_type_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        $query->orderByDesc('created_at');
        $paginator = $query->paginate($this->perPage($request, 25));
        $paginator->getCollection()->each(fn (QrCode $record) => $this->decorate($record));

        return response()->json($paginator);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());
        $token = Str::random(64);
        $record = QrCode::create([
            'reference' => $this->nextReference(),
            'token_hash' => hash('sha256', $token),
            'token_ciphertext' => Crypt::encryptString($token),
            'qr_type_id' => $validated['qr_type_id'],
            'label' => $validated['label'],
            'vehicle_number' => $validated['vehicle_number'] ?? null,
            'chassis_number' => $validated['chassis_number'] ?? null,
            'status' => $validated['status'] ?? 'active',
            'expires_at' => $validated['expires_at'] ?? null,
            'created_by' => $request->user()->id,
        ]);
        $this->audit->record('created', 'qr_codes', QrCode::class, $record->id, [], $validated);
        $this->decorate($record);

        return response()->json(['data' => $record], 201);
    }

    public function show(int $id): JsonResponse
    {
        $record = QrCode::query()->with('type')->findOrFail($id);
        $this->decorate($record);

        return response()->json(['data' => $record]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $record = QrCode::query()->findOrFail($id);
        $validated = $request->validate($this->rules($id, true));
        $old = $record->only(array_keys($validated));
        $record->update($validated);
        $this->audit->record('updated', 'qr_codes', QrCode::class, $record->id, $old, $validated);
        $this->decorate($record);

        return response()->json(['data' => $record->fresh('type')]);
    }

    public function activate(int $id): JsonResponse
    {
        return $this->setStatus($id, 'active', 'activated');
    }

    public function deactivate(int $id): JsonResponse
    {
        return $this->setStatus($id, 'inactive', 'deactivated');
    }

    public function regenerate(Request $request, int $id): JsonResponse
    {
        $record = QrCode::query()->findOrFail($id);
        $token = Str::random(64);
        $record->update([
            'token_hash' => hash('sha256', $token),
            'token_ciphertext' => Crypt::encryptString($token),
            'token_version' => $record->token_version + 1,
        ]);
        $this->audit->record('regenerated', 'qr_codes', QrCode::class, $record->id, ['token_version' => $record->token_version - 1], ['token_version' => $record->token_version]);
        $this->decorate($record);

        return response()->json(['data' => $record->fresh('type')]);
    }

    private function setStatus(int $id, string $status, string $action): JsonResponse
    {
        $record = QrCode::query()->findOrFail($id);
        $old = $record->status;
        $record->update(['status' => $status]);
        $this->audit->record($action, 'qr_codes', QrCode::class, $record->id, ['status' => $old], ['status' => $status]);
        $this->decorate($record);

        return response()->json(['data' => $record->fresh('type')]);
    }

    private function rules(?int $ignore = null, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $sometimes = $partial ? 'sometimes' : 'nullable';

        return [
            'qr_type_id' => [$required, 'integer', 'exists:qr_types,id'],
            'label' => [$required, 'string', 'max:160'],
            'vehicle_number' => [$sometimes, 'nullable', 'string', 'max:80'],
            'chassis_number' => [$sometimes, 'nullable', 'string', 'max:120'],
            'status' => [$partial ? 'sometimes' : 'nullable', 'in:active,inactive'],
            'expires_at' => [$sometimes, 'nullable', 'date', 'after:today', 'before_or_equal:9999-12-31 23:59:59'],
        ];
    }

    private function nextReference(): string
    {
        $year = now()->format('Y');
        $latest = QrCode::query()->where('reference', 'like', "QR-{$year}-%")->latest('id')->value('reference');
        $number = $latest ? ((int) substr($latest, -6)) + 1 : 1;
        return sprintf('QR-%s-%06d', $year, $number);
    }

    private function decorate(QrCode $record): void
    {
        $record->setAttribute('qr_url', $this->portal->qrUrl($record));
        $record->setAttribute('qr_payload', $this->portal->qrUrl($record));
    }
}
