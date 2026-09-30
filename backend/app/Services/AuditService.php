<?php

namespace App\Services;

class AuditService
{
    public function record(string $action, string $module, ?string $recordType = null, int|string|null $recordId = null, array $oldValues = [], array $newValues = []): void
    {
        $user = request()->user();

        if (! $user) {
            return;
        }

        \App\Models\AuditLog::create([
            'user_id' => $user->id,
            'action' => $action,
            'module' => $module,
            'record_type' => $recordType,
            'record_id' => $recordId === null ? null : (string) $recordId,
            'old_values' => $oldValues ?: null,
            'new_values' => $newValues ?: null,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'created_at' => now(),
        ]);
    }
}
