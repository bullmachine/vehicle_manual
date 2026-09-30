<?php

namespace App\Services;

class TotpService
{
    public function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\D+/', '', $code) ?? '';

        if (strlen($code) !== 6) {
            return false;
        }

        $timestamp = time() / 30;
        for ($offset = -$window; $offset <= $window; $offset++) {
            $expected = $this->codeAt($secret, (int) floor($timestamp) + $offset);
            if (hash_equals($expected, $code)) {
                return true;
            }
        }

        return false;
    }

    public function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?secret='.$secret.'&issuer='.rawurlencode($issuer).'&algorithm=SHA1&digits=6&period=30';
    }

    private function codeAt(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $hash = hash_hmac('sha1', pack('J', $counter), $key, true);
        $offset = ord($hash[19]) & 15;
        $value = ((ord($hash[$offset]) & 127) << 24)
            | ((ord($hash[$offset + 1]) & 255) << 16)
            | ((ord($hash[$offset + 2]) & 255) << 8)
            | (ord($hash[$offset + 3]) & 255);
        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = strtoupper(rtrim($secret, '='));
        $bits = '';

        for ($i = 0, $length = strlen($secret); $i < $length; $i++) {
            $index = strpos($alphabet, $secret[$i]);
            if ($index === false) {
                continue;
            }
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr(bindec($chunk));
            }
        }

        return $bytes;
    }
}
