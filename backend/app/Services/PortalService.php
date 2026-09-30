<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerSession;
use App\Models\Engine;
use App\Models\Language;
use App\Models\Manual;
use App\Models\QrCode;
use App\Models\QrScanLog;
use App\Models\VehicleEngineMapping;
use App\Models\VehicleVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PortalService
{
    public function findQr(string $token): QrCode
    {
        $qr = QrCode::query()
            ->with(['type', 'vehicleVariant', 'engine', 'language'])
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $qr) {
            throw ValidationException::withMessages(['qr' => 'This QR code is invalid or has been deactivated.']);
        }

        if ($qr->status !== 'active' || ($qr->expires_at && $qr->expires_at->isPast())) {
            throw ValidationException::withMessages(['qr' => 'This QR code is invalid or has been deactivated.']);
        }

        return $qr;
    }

    public function context(QrCode $qr): array
    {
        $vehicle = $qr->vehicleVariant;
        $engine = $qr->engine;
        $language = $qr->language;

        $vehicleOptions = $vehicle
            ? [$vehicle->only(['id', 'code', 'name'])]
            : $this->vehicleOptionsForType($qr->qr_type_id);

        $engineOptions = $engine
            ? [$engine->only(['id', 'code', 'name'])]
            : Engine::query()
                ->active()
                ->whereHas('mappings', fn ($query) => $query->whereIn('vehicle_variant_id', $this->variantIdsForType($qr->qr_type_id)))
                ->orderBy('name')
                ->get(['id', 'code', 'name'])
                ->all();

        $languageOptions = $language
            ? [$language->only(['id', 'code', 'name', 'native_name'])]
            : Language::query()->active()->orderBy('display_order')->orderBy('name')->get(['id', 'code', 'name', 'native_name'])->all();

        return [
            'qr' => [
                'reference' => $qr->reference,
                'type' => $qr->type?->only(['id', 'code', 'name']),
                'label' => $qr->label,
            ],
            'selection' => [
                'vehicle_variant_id' => $vehicle?->id,
                'vehicle_variant' => $vehicle?->only(['id', 'code', 'name']),
                'engine_id' => $engine?->id,
                'engine' => $engine?->only(['id', 'code', 'name']),
                'language_id' => $language?->id,
                'language' => $language?->only(['id', 'code', 'name', 'native_name']),
                'vehicle_required' => ! $vehicle,
                'engine_required' => ! $engine,
                'language_required' => ! $language,
            ],
            'options' => [
                'vehicle_variants' => $vehicleOptions,
                'engines' => $engineOptions,
                'languages' => $languageOptions,
            ],
        ];
    }

    public function vehicleOptionsForType(?int $qrTypeId): array
    {
        $base = VehicleVariant::query()->active()->orderBy('display_order')->orderBy('name');

        if ($qrTypeId) {
            $base->whereHas('qrTypes', fn ($query) => $query->where('qr_types.id', $qrTypeId));
        }

        $variants = $base->get(['id', 'code', 'name']);

        if ($variants->isEmpty() && $qrTypeId) {
            $variants = VehicleVariant::query()->active()->orderBy('display_order')->orderBy('name')->get(['id', 'code', 'name']);
        }

        return $variants->all();
    }

    private function variantIdsForType(?int $qrTypeId): array
    {
        return array_map(fn ($variant) => $variant['id'], $this->vehicleOptionsForType($qrTypeId));
    }

    public function resolveSelection(QrCode $qr, array $input): array
    {
        $vehicleId = $qr->vehicle_variant_id ?: ($input['vehicle_variant_id'] ?? null);
        $engineId = $qr->engine_id ?: ($input['engine_id'] ?? null);
        $languageId = $qr->language_id ?: ($input['language_id'] ?? null);

        if (! $vehicleId || ! $engineId || ! $languageId) {
            throw ValidationException::withMessages(['selection' => 'Select the missing vehicle information.']);
        }

        $vehicle = VehicleVariant::query()->active()->find($vehicleId);
        $engine = Engine::query()->active()->find($engineId);
        $language = Language::query()->active()->find($languageId);

        if (! $vehicle || ! $engine || ! $language) {
            throw ValidationException::withMessages(['selection' => 'One or more selected options are no longer available.']);
        }

        if (! $qr->vehicle_variant_id) {
            $allowed = array_column($this->vehicleOptionsForType($qr->qr_type_id), 'id');

            if (! in_array($vehicle->id, $allowed, true)) {
                throw ValidationException::withMessages(['vehicle_variant_id' => 'That vehicle variant is not available for this QR type.']);
            }
        }

        $mapped = VehicleEngineMapping::query()
            ->where('vehicle_variant_id', $vehicle->id)
            ->where('engine_id', $engine->id)
            ->exists();

        if (! $mapped) {
            throw ValidationException::withMessages(['engine_id' => 'That engine is not available for the selected vehicle.']);
        }

        return [
            'vehicle_variant_id' => $vehicle->id,
            'engine_id' => $engine->id,
            'language_id' => $language->id,
            'vehicle' => $vehicle,
            'engine' => $engine,
            'language' => $language,
        ];
    }

    public function createSession(QrCode $qr, array $selection, string $name, string $mobile, Request $request): array
    {
        $customer = Customer::query()->updateOrCreate(
            ['mobile' => $mobile],
            ['name' => $name, 'last_accessed_at' => now()]
        );

        $token = Str::random(96);
        $session = CustomerSession::create([
            'session_token_hash' => hash('sha256', $token),
            'customer_id' => $customer->id,
            'qr_code_id' => $qr->id,
            'vehicle_variant_id' => $selection['vehicle_variant_id'],
            'engine_id' => $selection['engine_id'],
            'language_id' => $selection['language_id'],
            'expires_at' => now()->addMinutes((int) config('portal.customer_session_minutes', 120)),
            'last_seen_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return ['token' => $token, 'session' => $session->load(['customer', 'vehicleVariant', 'engine', 'language'])];
    }

    public function manuals(CustomerSession $session): array
    {
        $manuals = Manual::query()
            ->published()
            ->where('vehicle_variant_id', $session->vehicle_variant_id)
            ->where('engine_id', $session->engine_id)
            ->where('language_id', $session->language_id)
            ->whereHas('qrTypes', fn ($query) => $query->where('qr_types.id', $session->qrCode->qr_type_id))
            ->whereHas('versions', fn ($query) => $query->where('status', 'published'))
            ->with(['manualType', 'vehicleVariant', 'engine', 'language', 'versions' => fn ($query) => $query->where('status', 'published')->orderByDesc('published_at')->orderByDesc('id')])
            ->orderByDesc('updated_at')
            ->get();

        return $manuals->map(function (Manual $manual) {
            $versions = $manual->versions;

            return [
                'id' => $manual->id,
                'title' => $manual->title,
                'type' => $manual->manualType?->name,
                'vehicle' => $manual->vehicleVariant?->name,
                'engine' => $manual->engine?->name,
                'language' => $manual->language?->name,
                'updated_at' => $manual->updated_at?->toIso8601String(),
                'versions' => $versions->map(fn ($version) => [
                    'id' => $version->id,
                    'version' => $version->version,
                    'published_at' => $version->published_at?->toIso8601String(),
                    'effective_date' => $version->effective_date?->toIso8601String(),
                    'page_count' => $version->page_count,
                ])->values(),
            ];
        })->values()->all();
    }

    public function authorizeManual(CustomerSession $session, int $manualId, ?int $versionId = null): array
    {
        $manual = Manual::query()
            ->published()
            ->whereKey($manualId)
            ->where('vehicle_variant_id', $session->vehicle_variant_id)
            ->where('engine_id', $session->engine_id)
            ->where('language_id', $session->language_id)
            ->whereHas('qrTypes', fn ($query) => $query->where('qr_types.id', $session->qrCode->qr_type_id))
            ->whereHas('versions', fn ($query) => $query->where('status', 'published'))
            ->firstOrFail();

        $versionQuery = $manual->versions()->where('status', 'published');
        if ($versionId) {
            $version = $versionQuery->findOrFail($versionId);
        } else {
            $version = $versionQuery->orderByDesc('published_at')->orderByDesc('id')->firstOrFail();
        }

        return [$manual, $version];
    }

    public function qrToken(QrCode $qr): string
    {
        return Crypt::decryptString($qr->token_ciphertext);
    }

    public function qrUrl(QrCode $qr): string
    {
        return rtrim((string) config('portal.frontend_url'), '/').'/q/'.$this->qrToken($qr);
    }

    public function markScan(QrCode $qr, Request $request, ?CustomerSession $session = null): void
    {
        $qr->increment('scan_count');
        $qr->forceFill(['last_scanned_at' => now()])->save();
        QrScanLog::create([
            'qr_code_id' => $qr->id,
            'customer_session_id' => $session?->id,
            'vehicle_variant_id' => $session?->vehicle_variant_id,
            'engine_id' => $session?->engine_id,
            'language_id' => $session?->language_id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device' => $request->header('X-Device-Name'),
        ]);
    }
}
