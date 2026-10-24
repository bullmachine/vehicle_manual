<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerSession;
use App\Models\Fert;
use App\Models\Language;
use App\Models\Machine;
use App\Models\Manual;
use App\Models\QrCode;
use App\Models\QrScanLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PortalService
{
    public function __construct(private readonly MachineLookup $machines, private readonly FertMaster $ferts)
    {
    }

    public function findQr(string $token): QrCode
    {
        $qr = QrCode::query()
            ->with('type')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $qr || $qr->status !== 'active' || ($qr->expires_at && $qr->expires_at->isPast())) {
            throw ValidationException::withMessages(['qr' => 'This QR code is invalid or has been deactivated.']);
        }

        return $qr;
    }

    public function context(QrCode $qr): array
    {
        return [
            'qr' => [
                'reference' => $qr->reference,
                'type' => $qr->type?->only(['id', 'code', 'name']),
                'label' => $qr->label,
            ],
            'languages' => Language::query()->active()->orderBy('display_order')->orderBy('name')->get(['id', 'code', 'name', 'native_name'])->all(),
        ];
    }

    public function normalizeSerialNo(string $serialNo): string
    {
        return Machine::normalizeSerialNo($serialNo);
    }

    /**
     * Resolve a machine serial number to its machine record and FERT master, and check
     * the machine may be opened from the scanned QR code's type (Domestic, Export, ...).
     *
     * @return array{machine: array, fert: Fert}
     */
    public function lookupMachine(string $serialNo, QrCode $qr): array
    {
        $machine = $this->machines->find($this->normalizeSerialNo($serialNo));

        if (! $machine) {
            throw ValidationException::withMessages(['machine_serial_no' => 'We could not find a machine with that serial number. Check the number on your machine plate and try again.']);
        }

        // New FERTs from machine data join the master automatically; admins then map manuals to them.
        $fert = filled($machine['fert']) ? $this->ferts->ensure($machine['fert'], $machine['description']) : null;

        if (! $fert || $fert->status !== 'active') {
            throw ValidationException::withMessages(['machine_serial_no' => 'Manuals for this machine are not available yet. Please contact support.']);
        }

        if (! $fert->allowsQrType($qr->qr_type_id)) {
            throw ValidationException::withMessages(['machine_serial_no' => 'This machine is not available on this QR code. Please scan the QR label on your machine or contact support.']);
        }

        return ['machine' => $machine, 'fert' => $fert];
    }

    /**
     * Active languages that have at least one published manual for the FERT.
     */
    public function languagesForFert(Fert $fert): array
    {
        return Language::query()
            ->active()
            ->whereHas('manuals', fn (Builder $query) => $this->availableManuals($query, $fert->id))
            ->orderBy('display_order')
            ->orderBy('name')
            ->get(['id', 'code', 'name', 'native_name'])
            ->all();
    }

    public function machineData(array $machine, Fert $fert): array
    {
        return [
            'serial_no' => $machine['msno'],
            'description' => $machine['description'] ?: $fert->name,
            'fert' => $fert->only(['id', 'code', 'name']),
        ];
    }

    public function resolveLanguage(int $languageId): Language
    {
        $language = Language::query()->active()->find($languageId);

        if (! $language) {
            throw ValidationException::withMessages(['language_id' => 'That language is no longer available.']);
        }

        return $language;
    }

    public function createSession(QrCode $qr, array $machine, Fert $fert, Language $language, string $name, ?string $mobile, Request $request): array
    {
        $customer = $mobile
            ? Customer::query()->updateOrCreate(['mobile' => $mobile], ['name' => $name, 'last_accessed_at' => now()])
            : Customer::query()->create(['name' => $name, 'mobile' => null, 'last_accessed_at' => now()]);

        $token = Str::random(96);
        $session = CustomerSession::create([
            'session_token_hash' => hash('sha256', $token),
            'customer_id' => $customer->id,
            'qr_code_id' => $qr->id,
            'fert_id' => $fert->id,
            'machine_serial_no' => $machine['msno'],
            'language_id' => $language->id,
            'expires_at' => now()->addMinutes((int) config('portal.customer_session_minutes', 120)),
            'last_seen_at' => now(),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
        $this->linkScan($qr, $session, $request);

        return ['token' => $token, 'session' => $session->load(['customer', 'fert', 'language'])];
    }

    public function sessionData(CustomerSession $session): array
    {
        return [
            'id' => $session->id,
            'customer' => $session->customer->only(['id', 'name', 'mobile']),
            'machine' => [
                'serial_no' => $session->machine_serial_no,
                'fert' => $session->fert?->only(['id', 'code', 'name']),
            ],
            'language' => $session->language->only(['id', 'name']),
            'expires_at' => $session->expires_at->toIso8601String(),
        ];
    }

    public function manuals(CustomerSession $session): array
    {
        $manuals = $this->availableManuals(Manual::query(), $session->fert_id)
            ->where('language_id', $session->language_id)
            ->with(['manualType', 'language', 'versions' => fn ($query) => $query->where('status', 'published')->orderByDesc('published_at')->orderByDesc('id')])
            ->orderByDesc('updated_at')
            ->get();

        return $manuals->map(function (Manual $manual) use ($session) {
            return [
                'id' => $manual->id,
                'title' => $manual->title,
                'type' => $manual->manualType?->name,
                'machine' => $session->fert?->name,
                'language' => $manual->language?->name,
                'updated_at' => $manual->updated_at?->toIso8601String(),
                'versions' => $manual->versions->map(fn ($version) => [
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
        $manual = $this->availableManuals(Manual::query(), $session->fert_id)
            ->whereKey($manualId)
            ->where('language_id', $session->language_id)
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
            'fert_id' => $session?->fert_id,
            'machine_serial_no' => $session?->machine_serial_no,
            'language_id' => $session?->language_id,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'device' => $request->header('X-Device-Name'),
        ]);
    }

    /**
     * Attach the machine details to the scan that started this session, so scan
     * reports show which machine each scan was for.
     */
    private function linkScan(QrCode $qr, CustomerSession $session, Request $request): void
    {
        $scan = QrScanLog::query()
            ->where('qr_code_id', $qr->id)
            ->whereNull('customer_session_id')
            ->where('ip_address', $request->ip())
            ->where('created_at', '>=', now()->subMinutes((int) config('portal.customer_session_minutes', 120)))
            ->latest('id')
            ->first();

        $scan?->update([
            'customer_session_id' => $session->id,
            'fert_id' => $session->fert_id,
            'machine_serial_no' => $session->machine_serial_no,
            'language_id' => $session->language_id,
        ]);
    }

    /**
     * Published manuals with a published version, mapped to the given FERT.
     */
    private function availableManuals(Builder $query, ?int $fertId): Builder
    {
        return $query
            ->published()
            ->whereHas('ferts', fn (Builder $builder) => $builder->whereKey($fertId ?? 0))
            ->whereHas('versions', fn (Builder $builder) => $builder->where('status', 'published'));
    }
}
