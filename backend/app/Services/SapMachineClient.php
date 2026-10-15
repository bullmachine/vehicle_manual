<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Client for the SAP (ABAP) machine data API.
 *
 * The API is a GET request with a JSON body ({API_TYP, PLANT, FROM_DT, TO_DT}) and
 * Basic auth. It returns every machine invoiced for the plant in the date range as
 * an array of {INVOICE, FERT, DESCRIPTION, PLANT, MSNO, DELIVERY_DATE}.
 */
class SapMachineClient
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetch(string $plant, Carbon $from, Carbon $to): array
    {
        $config = config('services.sap_machines');

        if (blank($config['url'] ?? null)) {
            throw new RuntimeException('SAP machine API URL is not configured. Set SAP_MACHINE_API_URL in .env.');
        }

        $payload = [
            'API_TYP' => $config['api_type'],
            'PLANT' => $plant,
            'FROM_DT' => $from->format('Ymd'),
            'TO_DT' => $to->format('Ymd'),
        ];

        try {
            $response = Http::withBasicAuth((string) $config['username'], (string) $config['password'])
                ->acceptJson()
                ->timeout((int) $config['timeout'])
                ->withBody(json_encode($payload), 'application/json')
                ->send('GET', $config['url']);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Could not reach the SAP machine API at '.$config['url'].': '.$exception->getMessage(), 0, $exception);
        }

        if ($response->status() === 401) {
            throw new RuntimeException('The SAP machine API rejected the credentials (HTTP 401). Check SAP_MACHINE_API_USERNAME and SAP_MACHINE_API_PASSWORD.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('The SAP machine API returned HTTP '.$response->status().'.');
        }

        $records = $response->json();

        // An empty date range may come back as an empty body rather than [].
        if ($records === null && trim($response->body()) === '') {
            return [];
        }

        if (! is_array($records) || ! array_is_list($records)) {
            throw new RuntimeException('The SAP machine API did not return a JSON array of machines.');
        }

        return array_values(array_filter($records, 'is_array'));
    }
}
