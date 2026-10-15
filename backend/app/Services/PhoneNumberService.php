<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use libphonenumber\numberParseException;

/**
 * Single source of truth for customer phone numbers.
 *
 * Every mobile entered by a customer is stored in E.164 form ("+<country code><number>"),
 * which is what the database columns and the UNIQUE constraint on customers.mobile assume.
 * A bare national number is read as the configured default region, so the portal behaves
 * exactly as it did for Indian users while accepting any other country.
 *
 * This talks to libphonenumber directly rather than through a facade, because a facade's
 * isValid() swallows parse errors and reports garbage input the same way it reports a
 * number that is merely too short, which would collapse two distinct messages into one.
 */
class PhoneNumberService
{
    /**
     * Building the full country list costs roughly 650ms, so it is cached across requests.
     * The allowlist is applied after the cache is read, so changing it never needs a flush.
     */
    private const COUNTRIES_CACHE_KEY = 'phone.countries.v1';

    private const COUNTRIES_CACHE_TTL = 86400;

    private function util(): PhoneNumberUtil
    {
        return PhoneNumberUtil::getInstance();
    }

    public function defaultRegion(): string
    {
        $region = strtoupper(trim((string) config('portal.phone.default_region', 'IN')));

        return $this->isValidRegion($region) ? $region : 'IN';
    }

    /**
     * Regions the platform accepts. An empty list means every region is allowed.
     *
     * @return list<string>
     */
    public function allowedRegions(): array
    {
        $allowed = [];

        foreach ((array) config('portal.phone.allowed_regions', []) as $region) {
            $region = strtoupper(trim((string) $region));
            if ($this->isValidRegion($region)) {
                $allowed[] = $region;
            }
        }

        return array_values(array_unique($allowed));
    }

    public function regionAllowed(string $region): bool
    {
        $allowed = $this->allowedRegions();

        return $allowed === [] || in_array(strtoupper(trim($region)), $allowed, true);
    }

    /**
     * Normalise user input to E.164, rejecting anything that is not a real number for its country.
     *
     * Accepts "+91 98765 43210", "919876543210", "09876543210" and the bare "9876543210"
     * (read as the default region) alongside any other country.
     */
    public function normalize(string $mobile, string $field = 'mobile'): string
    {
        $input = trim($mobile);

        if ($input === '') {
            throw ValidationException::withMessages([$field => 'Enter your mobile number.']);
        }

        $default = $this->defaultRegion();

        try {
            $parsed = $this->util()->parse($input, $default);
        } catch (numberParseException) {
            throw ValidationException::withMessages([
                $field => "Enter a valid mobile number. Include the country code for {$this->countryName($default)} ({$this->dialFor($default)}).",
            ]);
        }

        if (! $this->util()->isValidNumber($parsed)) {
            throw ValidationException::withMessages([
                $field => str_starts_with($input, '+')
                    ? 'That mobile number is not valid. Check the country code and the number of digits.'
                    : "That mobile number is not valid. For numbers outside {$this->countryName($default)}, start with + and the country code, e.g. +971 50 123 4567.",
            ]);
        }

        $region = $this->util()->getRegionCodeForNumber($parsed);

        if ($region === null || ! $this->regionAllowed($region)) {
            throw ValidationException::withMessages([
                $field => "Mobile numbers from {$this->countryName((string) $region)} are not accepted right now.",
            ]);
        }

        return $this->util()->format($parsed, PhoneNumberFormat::E164);
    }

    /**
     * Country list for the public mobile field. Filtered by the allowlist and sorted by name.
     *
     * @return list<array{code: string, dial: string, name: string}>
     */
    public function countries(): array
    {
        $rows = Cache::remember(self::COUNTRIES_CACHE_KEY, self::COUNTRIES_CACHE_TTL, function (): array {
            $util = $this->util();
            $rows = [];

            foreach ($util->getSupportedRegions() as $region) {
                $rows[] = [
                    'code' => $region,
                    'dial' => '+'.$util->getMetadataForRegion($region)->getCountryCode(),
                    'name' => $this->countryName($region),
                ];
            }

            usort($rows, fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

            return $rows;
        });

        $allowed = $this->allowedRegions();

        if ($allowed === []) {
            return $rows;
        }

        return array_values(array_filter($rows, fn (array $row): bool => in_array($row['code'], $allowed, true)));
    }

    /**
     * Human-readable form of a stored number, e.g. "+971 50 123 4567".
     */
    public function formatForDisplay(?string $e164): ?string
    {
        $parsed = $this->parseStored($e164);

        if ($parsed === null) {
            return $e164;
        }

        return $this->util()->format($parsed, PhoneNumberFormat::INTERNATIONAL);
    }

    /**
     * ISO 3166-1 alpha-2 region of a stored number, derived on read so it can never drift.
     */
    public function regionFor(?string $e164): ?string
    {
        $parsed = $this->parseStored($e164);

        if ($parsed === null) {
            return null;
        }

        return $this->util()->getRegionCodeForNumber($parsed);
    }

    public function countryName(string $region): string
    {
        $region = strtoupper(trim($region));

        if ($region === '') {
            return 'that country';
        }

        // The intl extension is not always enabled (e.g. a default XAMPP PHP); fall back to common names.
        if (! class_exists(\Locale::class)) {
            return self::FALLBACK_COUNTRY_NAMES[$region] ?? $region;
        }

        $name = \Locale::getDisplayRegion('-'.$region, 'en');

        return $name === '' ? $region : $name;
    }

    private const FALLBACK_COUNTRY_NAMES = [
        'IN' => 'India', 'AE' => 'United Arab Emirates', 'SA' => 'Saudi Arabia', 'QA' => 'Qatar', 'OM' => 'Oman',
        'KW' => 'Kuwait', 'BH' => 'Bahrain', 'LK' => 'Sri Lanka', 'NP' => 'Nepal', 'BD' => 'Bangladesh',
        'SG' => 'Singapore', 'MY' => 'Malaysia', 'GB' => 'United Kingdom', 'US' => 'United States',
    ];

    public function dialFor(string $region): string
    {
        $metadata = $region === '' ? null : $this->util()->getMetadataForRegion($region);

        return $metadata === null ? '' : '+'.$metadata->getCountryCode();
    }

    private function isValidRegion(string $region): bool
    {
        return $region !== '' && $this->util()->getMetadataForRegion($region) !== null;
    }

    /**
     * Parse a number already in storage, tolerating any legacy or hand-edited value.
     */
    private function parseStored(?string $e164): ?PhoneNumber
    {
        if (! $e164) {
            return null;
        }

        try {
            return $this->util()->parse($e164, null);
        } catch (numberParseException) {
            return null;
        }
    }
}
