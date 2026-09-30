<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSettingController extends Controller
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json(['data' => [
            'otp_enabled' => (bool) (Setting::value('otp_enabled', config('portal.otp.enabled'))),
            'otp_length' => (int) Setting::value('otp_length', config('portal.otp.length')),
            'otp_expiry_minutes' => (int) Setting::value('otp_expiry_minutes', config('portal.otp.expiry_minutes')),
            'otp_max_attempts' => (int) Setting::value('otp_max_attempts', config('portal.otp.max_attempts')),
            'otp_resend_cooldown' => (int) Setting::value('otp_resend_cooldown', config('portal.otp.resend_cooldown')),
            'manual_max_file_size' => (int) Setting::value('manual_max_file_size', config('portal.manual_max_file_size')),
            'support_contact' => Setting::value('support_contact', ''),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'otp_enabled' => ['required', 'boolean'],
            'otp_length' => ['required', 'integer', 'min:4', 'max:8'],
            'otp_expiry_minutes' => ['required', 'integer', 'min:1', 'max:30'],
            'otp_max_attempts' => ['required', 'integer', 'min:1', 'max:10'],
            'otp_resend_cooldown' => ['required', 'integer', 'min:10', 'max:600'],
            'manual_max_file_size' => ['required', 'integer', 'min:1048576'],
            'support_contact' => ['nullable', 'string', 'max:160'],
        ]);
        $old = $this->show()->getData(true)['data'] ?? [];
        foreach ($validated as $key => $value) {
            $type = is_bool($value) ? 'boolean' : (is_int($value) ? 'integer' : 'string');
            Setting::updateOrCreate(['key' => $key], ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value, 'type' => $type, 'updated_by' => $request->user()->id]);
        }
        config(['portal.otp.enabled' => $validated['otp_enabled'], 'portal.otp.length' => $validated['otp_length'], 'portal.otp.expiry_minutes' => $validated['otp_expiry_minutes'], 'portal.otp.max_attempts' => $validated['otp_max_attempts'], 'portal.otp.resend_cooldown' => $validated['otp_resend_cooldown'], 'portal.manual_max_file_size' => $validated['manual_max_file_size']]);
        $this->audit->record('updated', 'settings', Setting::class, null, $old, $validated);

        return $this->show();
    }
}
