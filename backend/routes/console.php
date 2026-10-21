<?php

use App\Services\MachineSync;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('machines:sync
    {--plant=* : Plant code(s); defaults to SAP_MACHINE_PLANTS}
    {--from= : Start date (YYYYMMDD or YYYY-MM-DD); defaults to SAP_MACHINE_SYNC_DAYS ago}
    {--to= : End date (YYYYMMDD or YYYY-MM-DD); defaults to today}
    {--chunk=31 : Days per SAP request}
    {--file= : Load a JSON file in the SAP response format instead of calling SAP}', function (MachineSync $sync) {
    $report = function (string $label, array $result): void {
        $line = "{$label}: {$result['received']} received, {$result['created']} new, {$result['updated']} updated, {$result['skipped']} skipped, {$result['ferts_added']} new FERTs.";
        $this->info($line);
        // Also logged so runs from Windows Task Scheduler, which has no console, leave a record.
        Log::info("machines:sync {$line}");
    };

    try {
        if ($file = $this->option('file')) {
            $report($file, $sync->syncFromFile($file));

            return 0;
        }

        $parse = fn (string $value) => Carbon::createFromFormat(preg_match('/^\d{8}$/', $value) ? '!Ymd' : '!Y-m-d', $value);
        $to = $this->option('to') ? $parse($this->option('to')) : today();
        $from = $this->option('from') ? $parse($this->option('from')) : today()->subDays(max((int) config('services.sap_machines.sync_days'), 1));

        if ($from->gt($to)) {
            $this->error('--from must be on or before --to.');

            return 1;
        }

        $plants = $this->option('plant') ?: config('services.sap_machines.plants');
        $sync->syncPlants($plants, $from, $to, (int) $this->option('chunk'), 'command', fn (string $plant, array $result) => $report("Plant {$plant} {$from->format('Ymd')}-{$to->format('Ymd')}", $result));
    } catch (\Throwable $exception) {
        $this->error($exception->getMessage());
        Log::error('machines:sync failed: '.$exception->getMessage());

        return 1;
    }

    return 0;
})->purpose('Sync machine serial numbers and FERTs from the SAP machine API');

// Pull recently invoiced machines (last SAP_MACHINE_SYNC_DAYS days) from SAP every Monday and Thursday.
// On the server this is run by the Windows task "Vehicle Manual - SAP machine sync" calling
// `php artisan machines:sync` directly, so `schedule:run` must not also be scheduled there or it would sync twice.
Schedule::command('machines:sync')->days([1, 4])->at('07:30')->timezone('Asia/Kolkata')->withoutOverlapping();
