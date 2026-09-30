<?php

namespace App\Services;

use App\Models\Manual;
use App\Models\ManualAccessLog;
use App\Models\CustomerSession;
use Illuminate\Http\Request;

class ManualAccessService
{
    public function record(CustomerSession $session, Manual $manual, \App\Models\ManualVersion $version, string $event, Request $request, ?int $page = null): void
    {
        ManualAccessLog::create([
            'manual_id' => $manual->id,
            'manual_version_id' => $version->id,
            'customer_id' => $session->customer_id,
            'customer_session_id' => $session->id,
            'event' => $event,
            'page_number' => $page,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);
    }
}
