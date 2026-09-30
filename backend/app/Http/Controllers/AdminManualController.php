<?php

namespace App\Http\Controllers;

use App\Models\Manual;
use App\Models\Setting;
use App\Models\ManualVersion;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminManualController extends Controller
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $query = Manual::query()->with(['manualType', 'vehicleVariant', 'engine', 'language', 'versions' => fn ($query) => $query->latest('published_at')->latest('id')]);
        $this->applyFilters($query, $request);
        $query->orderByDesc('updated_at')->orderByDesc('id');

        return response()->json($query->paginate((int) $request->input('per_page', 25)));
    }

    public function show(int $manualId): JsonResponse
    {
        $manual = Manual::query()->with(['manualType', 'vehicleVariant', 'engine', 'language', 'qrTypes', 'versions' => fn ($query) => $query->orderByDesc('published_at')->orderByDesc('id')])->findOrFail($manualId);

        return response()->json(['data' => $manual]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:180'],
            'manual_type_id' => ['required', 'integer', 'exists:manual_types,id'],
            'vehicle_variant_id' => ['required', 'integer', 'exists:vehicle_variants,id'],
            'engine_id' => ['required', 'integer', 'exists:engines,id'],
            'language_id' => ['required', 'integer', 'exists:languages,id'],
            'version' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/'],
            'effective_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:3000'],
            'status' => ['nullable', 'in:draft,pending_review,published'],
            'qr_type_ids' => ['required', 'array', 'min:1'],
            'qr_type_ids.*' => ['integer', 'exists:qr_types,id'],
            'pdf' => ['required', 'file'],
        ]);
        $this->validatePdf($request->file('pdf'));
        $status = $validated['status'] ?? 'draft';

        $manual = DB::transaction(function () use ($request, $validated, $status) {
            $file = $request->file('pdf');
            $path = $file->store('manuals', 'local');
            $publishedAt = $status === 'published' ? now() : null;
            $manual = Manual::create([
                'title' => $validated['title'],
                'manual_type_id' => $validated['manual_type_id'],
                'vehicle_variant_id' => $validated['vehicle_variant_id'],
                'engine_id' => $validated['engine_id'],
                'language_id' => $validated['language_id'],
                'status' => $status,
                'created_by' => $request->user()->id,
            ]);
            $manual->qrTypes()->sync($validated['qr_type_ids']);
            $version = $manual->versions()->create([
                'version' => $validated['version'],
                'effective_date' => $validated['effective_date'] ?? null,
                'description' => $validated['description'] ?? null,
                'file_disk' => 'local',
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => 'application/pdf',
                'file_size' => $file->getSize(),
                'checksum' => hash_file('sha256', $file->getRealPath()),
                'page_count' => $this->pageCount($file->getRealPath()),
                'status' => $status,
                'published_at' => $publishedAt,
                'created_by' => $request->user()->id,
            ]);

            return [$manual, $version];
        });
        $this->audit->record('uploaded', 'manuals', Manual::class, $manual[0]->id, [], ['title' => $manual[0]->title, 'version' => $manual[1]->version]);

        return response()->json(['data' => $manual[0]->load(['versions', 'qrTypes'])], 201);
    }

    public function update(Request $request, int $manualId): JsonResponse
    {
        $manual = Manual::query()->findOrFail($manualId);
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:180'],
            'manual_type_id' => ['sometimes', 'integer', 'exists:manual_types,id'],
            'vehicle_variant_id' => ['sometimes', 'integer', 'exists:vehicle_variants,id'],
            'engine_id' => ['sometimes', 'integer', 'exists:engines,id'],
            'language_id' => ['sometimes', 'integer', 'exists:languages,id'],
            'qr_type_ids' => ['sometimes', 'array', 'min:1'],
            'qr_type_ids.*' => ['integer', 'exists:qr_types,id'],
        ]);
        $old = $manual->only(array_keys($validated));
        $manual->update($validated);
        if (isset($validated['qr_type_ids'])) {
            $manual->qrTypes()->sync($validated['qr_type_ids']);
        }
        $this->audit->record('updated', 'manuals', Manual::class, $manual->id, $old, $validated);

        return response()->json(['data' => $manual->fresh(['versions', 'qrTypes'])]);
    }

    public function storeVersion(Request $request, int $manualId): JsonResponse
    {
        $manual = Manual::query()->findOrFail($manualId);
        $validated = $request->validate([
            'version' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('manual_versions', 'version')->where('manual_id', $manual->id)],
            'effective_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:3000'],
            'status' => ['nullable', 'in:draft,pending_review,published'],
            'pdf' => ['required', 'file'],
        ]);
        $this->validatePdf($request->file('pdf'));
        $file = $request->file('pdf');
        $path = $file->store('manuals', 'local');
        $status = $validated['status'] ?? 'draft';
        $version = $manual->versions()->create([
            'version' => $validated['version'],
            'effective_date' => $validated['effective_date'] ?? null,
            'description' => $validated['description'] ?? null,
            'file_disk' => 'local',
            'file_path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => 'application/pdf',
            'file_size' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'page_count' => $this->pageCount($file->getRealPath()),
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
            'created_by' => $request->user()->id,
        ]);
        if ($status === 'published') {
            $manual->update(['status' => 'published']);
        }
        $this->audit->record('version_uploaded', 'manuals', ManualVersion::class, $version->id, [], Arr::only($validated, ['version', 'effective_date', 'description', 'status']));

        return response()->json(['data' => $version], 201);
    }

    public function publish(int $manualId): JsonResponse
    {
        $manual = Manual::query()->with('versions')->findOrFail($manualId);
        $version = $manual->versions()->whereIn('status', ['draft', 'pending_review', 'published'])->orderByDesc('id')->firstOrFail();
        DB::transaction(function () use ($manual, $version) {
            $version->update(['status' => 'published', 'published_at' => now()]);
            $manual->update(['status' => 'published']);
        });
        $this->audit->record('published', 'manuals', Manual::class, $manual->id, ['status' => $manual->status], ['status' => 'published', 'version_id' => $version->id]);

        return response()->json(['data' => $manual->fresh(['versions'])]);
    }

    public function unpublish(int $manualId): JsonResponse
    {
        $manual = Manual::query()->findOrFail($manualId);
        $manual->update(['status' => 'unpublished']);
        $this->audit->record('unpublished', 'manuals', Manual::class, $manual->id, ['status' => $manual->status], ['status' => 'unpublished']);

        return response()->json(['data' => $manual->fresh()]);
    }

    public function archive(int $manualId): JsonResponse
    {
        $manual = Manual::query()->findOrFail($manualId);
        $manual->update(['status' => 'archived']);
        $manual->versions()->update(['status' => 'archived']);
        $this->audit->record('archived', 'manuals', Manual::class, $manual->id, ['status' => $manual->status], ['status' => 'archived']);

        return response()->json(['data' => $manual->fresh()]);
    }

    public function archiveVersion(int $manualId, int $versionId): JsonResponse
    {
        $manual = Manual::query()->findOrFail($manualId);
        $version = $manual->versions()->findOrFail($versionId);

        if ($version->status === 'archived') {
            throw ValidationException::withMessages(['version_id' => 'This version is already archived.']);
        }

        $previous = $version->status;
        if ($previous === 'published') {
            $hasOtherPublished = $manual->versions()
                ->where('status', 'published')
                ->where('id', '!=', $version->id)
                ->exists();

            if (! $hasOtherPublished) {
                throw ValidationException::withMessages(['version_id' => 'A manual must keep at least one published version. Unpublish or archive the whole manual instead.']);
            }
        }

        DB::transaction(function () use ($version) {
            $version->update(['status' => 'archived']);
        });
        $this->audit->record('version_archived', 'manuals', ManualVersion::class, $version->id, ['status' => $previous], ['status' => 'archived']);

        return response()->json(['data' => $version->fresh()]);
    }

    private function applyFilters($query, Request $request): void
    {
        foreach (['status', 'manual_type_id', 'vehicle_variant_id', 'engine_id', 'language_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->filled('q')) {
            $query->where('title', 'like', '%'.$request->input('q').'%');
        }
    }

    private function validatePdf(UploadedFile $file): void
    {
        $max = (int) Setting::value('manual_max_file_size', config('portal.manual_max_file_size', 52428800));
        $validMime = in_array($file->getMimeType(), ['application/pdf', 'application/x-pdf'], true);
        $validExtension = strtolower($file->getClientOriginalExtension()) === 'pdf';
        $header = $file->getRealPath() ? file_get_contents($file->getRealPath(), false, null, 0, 5) : '';
        if (! $file->isValid() || ! $validMime || ! $validExtension || $header !== '%PDF-' || $file->getSize() > $max) {
            throw ValidationException::withMessages(['pdf' => 'Upload a valid PDF file no larger than the configured limit.']);
        }
    }

    private function pageCount(string $path): int
    {
        if (! extension_loaded('imagick')) {
            return 0;
        }
        try {
            $image = new \Imagick($path);
            $count = $image->getNumberImages();
            $image->destroy();
            return $count;
        } catch (\Throwable) {
            return 0;
        }
    }
}
