<?php

namespace App\Services;

use App\Models\Fert;
use App\Models\Machine;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Copies machine records from SAP (or a JSON file in the same format) into the local
 * machines table and adds any new FERTs to the master.
 */
class MachineSync
{
    public function __construct(private readonly SapMachineClient $sap, private readonly FertMaster $ferts)
    {
    }

    /**
     * Sync one plant from SAP, splitting long ranges into chunks so each request stays small.
     *
     * @return array{received: int, created: int, updated: int, skipped: int, ferts_added: int}
     */
    public function syncFromSap(string $plant, Carbon $from, Carbon $to, int $chunkDays = 31): array
    {
        $totals = ['received' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'ferts_added' => 0];
        $cursor = $from->copy()->startOfDay();

        while ($cursor->lte($to)) {
            $chunkEnd = $cursor->copy()->addDays(max($chunkDays, 1) - 1)->min($to);
            $result = $this->store($this->sap->fetch($plant, $cursor, $chunkEnd), 'sap');
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + $result[$key];
            }
            $cursor = $chunkEnd->copy()->addDay();
        }

        return $totals;
    }

    /**
     * Load machines from a JSON file shaped like the SAP response (used for dummy data).
     *
     * @return array{received: int, created: int, updated: int, skipped: int, ferts_added: int}
     */
    public function syncFromFile(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException("Machine data file not found: {$path}");
        }

        $records = json_decode((string) file_get_contents($path), true);
        if (! is_array($records) || ! array_is_list($records)) {
            throw new RuntimeException("Machine data file must contain a JSON array: {$path}");
        }

        return $this->store(array_values(array_filter($records, 'is_array')), 'file');
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array{received: int, created: int, updated: int, skipped: int, ferts_added: int}
     */
    public function store(array $records, string $source): array
    {
        $result = ['received' => count($records), 'created' => 0, 'updated' => 0, 'skipped' => 0, 'ferts_added' => 0];

        foreach ($records as $record) {
            $record = array_change_key_case($record, CASE_UPPER);
            $serials = $this->serialNumbers((string) ($record['MSNO'] ?? ''));
            $fert = trim((string) ($record['FERT'] ?? ''));

            if ($serials === [] || $fert === '' || strlen($fert) > 40) {
                $result['skipped']++;
                continue;
            }

            $description = filled($record['DESCRIPTION'] ?? null) ? trim((string) $record['DESCRIPTION']) : null;
            if (! Fert::query()->where('code', $fert)->exists()) {
                $this->ferts->ensure($fert, $description);
                $result['ferts_added']++;
            }

            foreach ($serials as $msno) {
                $machine = Machine::query()->updateOrCreate(['msno' => $msno], [
                    'fert' => $fert,
                    'description' => $description,
                    'invoice' => filled($record['INVOICE'] ?? null) ? trim((string) $record['INVOICE']) : null,
                    'plant' => filled($record['PLANT'] ?? null) ? trim((string) $record['PLANT']) : null,
                    'delivery_date' => $this->parseDate($record['DELIVERY_DATE'] ?? null),
                    'source' => $source,
                    'synced_at' => now(),
                ]);

                $result[$machine->wasRecentlyCreated ? 'created' : 'updated']++;
            }
        }

        return $result;
    }

    /**
     * Sync several plants and record the outcome (including failures) as the latest run.
     *
     * @param  callable(string, array): void|null  $onPlant  called with each plant's result
     * @return array{received: int, created: int, updated: int, skipped: int, ferts_added: int}
     */
    public function syncPlants(array $plants, Carbon $from, Carbon $to, int $chunkDays = 31, ?string $by = null, ?callable $onPlant = null): array
    {
        $totals = ['received' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'ferts_added' => 0];

        try {
            foreach ($plants as $plant) {
                $result = $this->syncFromSap((string) $plant, $from, $to, $chunkDays);
                foreach ($totals as $key => $value) {
                    $totals[$key] = $value + $result[$key];
                }
                if ($onPlant) {
                    $onPlant((string) $plant, $result);
                }
            }
        } catch (\Throwable $exception) {
            $this->recordRun($plants, $from, $to, $totals, $exception->getMessage(), $by);
            throw $exception;
        }

        $this->recordRun($plants, $from, $to, $totals, null, $by);

        return $totals;
    }

    /**
     * Remember the outcome of the latest SAP sync so admins can see it.
     */
    public function recordRun(array $plants, Carbon $from, Carbon $to, ?array $result, ?string $error = null, ?string $by = null): void
    {
        Setting::query()->updateOrCreate(['key' => 'machines_last_sync'], [
            'type' => 'json',
            'value' => json_encode([
                'at' => now()->toIso8601String(),
                'status' => $error ? 'failed' : 'success',
                'plants' => array_values($plants),
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'result' => $result,
                'error' => $error,
                'by' => $by ?? 'scheduler',
            ]),
        ]);
    }

    public function lastRun(): ?array
    {
        $value = Setting::value('machines_last_sync');

        return is_array($value) ? $value : null;
    }

    /**
     * Split SAP's MSNO field into individual serial numbers.
     *
     * One invoice line can carry several machines, joined with "/" or ",", and the
     * value may start with the SAP item position, e.g. "10.HP43C00865/HP43C00866".
     *
     * @return array<int, string>
     */
    public function serialNumbers(string $value): array
    {
        $serials = [];

        foreach (preg_split('#[/,;]#', $value) ?: [] as $part) {
            // Drop leading dots and the item position prefix ("10.", ".10.").
            $part = preg_replace('/^[.\s]*(\d{1,4}\.)?/', '', $part) ?? '';
            $serial = $this->normalizeSerialNo($part);

            if ($serial !== '' && strlen($serial) <= 60 && preg_match('/[A-Z0-9]/', $serial)) {
                $serials[$serial] = $serial;
            }
        }

        return array_values($serials);
    }

    public function normalizeSerialNo(string $serialNo): string
    {
        return Machine::normalizeSerialNo($serialNo);
    }

    private function parseDate(mixed $value): ?string
    {
        $value = trim((string) $value);

        if (! preg_match('/^\d{8}$/', $value) || $value === '00000000') {
            return null;
        }

        $date = Carbon::createFromFormat('!Ymd', $value);

        return $date && $date->format('Ymd') === $value ? $date->toDateString() : null;
    }
}
