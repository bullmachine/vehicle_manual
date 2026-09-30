<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;

class TwoFactorService
{
    public function __construct(private readonly TotpService $totp)
    {
    }

    public function enable(User $user): array
    {
        $secret = $this->newSecret();
        $recovery = collect(range(1, 8))->map(fn () => strtoupper(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill([
            'two_factor_secret' => encrypt($secret),
            'two_factor_recovery_codes' => $recovery,
            'two_factor_confirmed_at' => null,
        ])->save();

        return [
            'secret' => $secret,
            'recovery_codes' => $recovery,
            'provisioning_uri' => $this->totp->provisioningUri($secret, $user->email, config('app.name')),
        ];
    }

    public function confirm(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret ? decrypt($user->two_factor_secret) : null;

        if (! $secret || ! $this->totp->verify($secret, $code)) {
            return false;
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return true;
    }

    public function verify(User $user, string $code): bool
    {
        $secret = $user->two_factor_secret ? decrypt($user->two_factor_secret) : null;

        if ($secret && $this->totp->verify($secret, $code)) {
            return true;
        }

        $recovery = $user->two_factor_recovery_codes ?? [];
        $key = strtoupper($code);
        $index = array_search($key, array_map('strtoupper', $recovery), true);

        if ($index === false) {
            return false;
        }

        unset($recovery[$index]);
        $user->forceFill(['two_factor_recovery_codes' => array_values($recovery)])->save();

        return true;
    }

    private function newSecret(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';

        for ($i = 0; $i < 32; $i++) {
            $secret .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $secret;
    }
}
