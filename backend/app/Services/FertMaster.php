<?php

namespace App\Services;

use App\Models\Fert;
use App\Models\QrType;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Keeps the FERT master in step with machine data and bulk imports.
 */
class FertMaster
{
    /**
     * Return the FERT for a code, adding it to the master when it is new.
     */
    public function ensure(string $code, ?string $name = null): Fert
    {
        $code = trim($code);

        return Fert::query()->firstOrCreate(
            ['code' => $code],
            ['name' => filled($name) ? trim((string) $name) : 'FERT '.$code, 'status' => 'active']
        );
    }

    /**
     * Import FERTs from a JSON array or a CSV file with a header row. Each row needs a
     * FERT (or code) column; DESCRIPTION (or name) is optional. An optional QR_TYPE column
     * maps the FERT to QR types by code or name, several separated by ";" or "|"
     * (e.g. "domestic;export"); "all" clears the mapping, an empty cell leaves it unchanged.
     * The machine API JSON itself can be imported, since it carries FERT and DESCRIPTION.
     *
     * @return array{created: int, updated: int, skipped: int, total: int}
     */
    public function import(UploadedFile $file): array
    {
        $rows = $this->rows($file);
        $created = $updated = $skipped = 0;
        $seen = [];
        $qrTypes = QrType::query()->get(['id', 'code', 'name']);

        foreach ($rows as $row) {
            $row = array_change_key_case(array_map(fn ($value) => is_scalar($value) ? trim((string) $value) : '', $row), CASE_LOWER);
            $code = $row['fert'] ?? $row['code'] ?? '';
            $name = $row['description'] ?? $row['name'] ?? '';

            if ($code === '' || strlen($code) > 40 || isset($seen[$code])) {
                $skipped++;
                continue;
            }
            $seen[$code] = true;

            $qrTypeIds = $this->qrTypeIds($row['qr_type'] ?? $row['qr_types'] ?? '', $qrTypes);

            $fert = Fert::query()->where('code', $code)->first();
            if (! $fert) {
                $fert = Fert::query()->create(['code' => $code, 'name' => $name !== '' ? mb_substr($name, 0, 160) : 'FERT '.$code, 'status' => 'active']);
                if ($qrTypeIds !== null) {
                    $fert->qrTypes()->sync($qrTypeIds);
                }
                $created++;

                continue;
            }

            $changed = false;
            if ($name !== '' && $fert->name !== $name) {
                $fert->update(['name' => mb_substr($name, 0, 160)]);
                $changed = true;
            }
            if ($qrTypeIds !== null) {
                $sync = $fert->qrTypes()->sync($qrTypeIds);
                $changed = $changed || $sync['attached'] !== [] || $sync['detached'] !== [];
            }
            $changed ? $updated++ : $skipped++;
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped, 'total' => count($rows)];
    }

    /**
     * QR type ids for an import cell, or null when the cell is empty (leave the mapping as it is).
     *
     * @param  \Illuminate\Support\Collection<int, QrType>  $qrTypes
     * @return array<int, int>|null
     */
    private function qrTypeIds(string $cell, $qrTypes): ?array
    {
        $values = array_values(array_filter(array_map(fn ($value) => strtolower(trim($value)), preg_split('/[;|]/', $cell) ?: [])));

        if ($values === []) {
            return null;
        }
        if ($values === ['all']) {
            return [];
        }

        $ids = [];
        foreach ($values as $value) {
            $match = $qrTypes->first(fn (QrType $type) => strtolower($type->code) === $value || strtolower($type->name) === $value);
            if (! $match) {
                throw ValidationException::withMessages(['file' => "Unknown QR type \"{$value}\". Use one of: ".$qrTypes->pluck('code')->implode(', ').', or all.']);
            }
            $ids[] = $match->id;
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(UploadedFile $file): array
    {
        $contents = (string) file_get_contents($file->getRealPath());
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        if (strtolower($file->getClientOriginalExtension()) === 'json') {
            $data = json_decode($contents, true);
            if (! is_array($data)) {
                throw ValidationException::withMessages(['file' => 'The JSON file must contain an array of FERT records.']);
            }

            return array_values(array_filter($data, 'is_array'));
        }

        $lines = array_values(array_filter(preg_split('/\r\n|\n|\r/', $contents) ?: [], fn ($line) => trim($line) !== ''));
        if (count($lines) < 2) {
            throw ValidationException::withMessages(['file' => 'The CSV file needs a header row (FERT, DESCRIPTION) and at least one record.']);
        }
        $header = str_getcsv(array_shift($lines));

        return array_map(fn ($line) => array_combine($header, array_pad(array_slice(str_getcsv($line), 0, count($header)), count($header), '')), $lines);
    }
}
