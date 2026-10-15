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
            'manual_max_file_size' => (int) Setting::value('manual_max_file_size', config('portal.manual_max_file_size')),
            'support_contact' => Setting::value('support_contact', ''),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'manual_max_file_size' => ['required', 'integer', 'min:1048576'],
            'support_contact' => ['nullable', 'string', 'max:160'],
        ]);
        $old = $this->show()->getData(true)['data'] ?? [];
        foreach ($validated as $key => $value) {
            $type = is_bool($value) ? 'boolean' : (is_int($value) ? 'integer' : 'string');
            Setting::updateOrCreate(['key' => $key], ['value' => is_bool($value) ? ($value ? '1' : '0') : (string) $value, 'type' => $type, 'updated_by' => $request->user()->id]);
        }
        config(['portal.manual_max_file_size' => $validated['manual_max_file_size']]);
        $this->audit->record('updated', 'settings', Setting::class, null, $old, $validated);

        return $this->show();
    }
}
