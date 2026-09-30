<?php

namespace App\Services;

use App\Models\OtpVerification;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OtpService
{
    public function __construct(private readonly SmsProvider $smsProvider, private readonly PhoneNumberService $phones)
    {
    }

    public function enabled(): bool
    {
        return (bool) Setting::value('otp_enabled', config('portal.otp.enabled'));
    }

    public function length(): int
    {
        return min(max((int) Setting::value('otp_length', config('portal.otp.length', 6)), 4), 8);
    }

    public function request(string $mobile, Request $request): array
    {
        $mobile = $this->phones->normalize($mobile);
        $key = 'otp-request:'.$mobile;
        $window = (int) config('portal.otp.window_minutes', 15);
        $maxRequests = (int) config('portal.otp.requests_per_window', 5);

        if (RateLimiter::tooManyAttempts($key, $maxRequests)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages(['mobile' => "Too many OTP requests. Try again in {$seconds} seconds."]);
        }

        $cooldown = (int) Setting::value('otp_resend_cooldown', config('portal.otp.resend_cooldown', 30));
        $last = OtpVerification::query()
            ->where('mobile', $mobile)
            ->whereNull('verified_at')
            ->where('created_at', '>', now()->subSeconds($cooldown))
            ->latest('id')
            ->first();

        if ($last) {
            $seconds = max(1, $cooldown - now()->diffInSeconds($last->created_at, false));
            throw ValidationException::withMessages(['mobile' => "Please wait {$seconds} seconds before requesting another OTP."]);
        }

        OtpVerification::query()
            ->where('mobile', $mobile)
            ->whereNull('verified_at')
            ->update(['invalidated_at' => now()]);

        $length = $this->length();
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
        $record = OtpVerification::create([
            'mobile' => $mobile,
            'otp_hash' => $this->hash($mobile, $code),
            'expires_at' => now()->addMinutes((int) Setting::value('otp_expiry_minutes', config('portal.otp.expiry_minutes', 5))),
            'max_attempts' => (int) Setting::value('otp_max_attempts', config('portal.otp.max_attempts', 5)),
            'sent_at' => now(),
            'request_ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        RateLimiter::hit($key, $window * 60);
        $this->smsProvider->send($mobile, "Your BULL manual verification code is {$code}. It expires in ".(int) Setting::value('otp_expiry_minutes', config('portal.otp.expiry_minutes', 5)).' minutes.');

        return [
            'verification_id' => $record->id,
            'expires_in' => now()->diffInSeconds($record->expires_at, false),
            'resend_in' => $cooldown,
            'debug_otp' => app()->environment('local') ? $code : null,
        ];
    }

    public function verify(string $mobile, string $code): OtpVerification
    {
        $mobile = $this->phones->normalize($mobile);
        $record = OtpVerification::query()
            ->where('mobile', $mobile)
            ->whereNull('verified_at')
            ->whereNull('invalidated_at')
            ->latest('id')
            ->first();

        if (! $record || $record->expires_at->isPast()) {
            throw ValidationException::withMessages(['otp' => 'Your OTP has expired. Please request a new one.']);
        }

        if ($record->attempts >= $record->max_attempts) {
            throw ValidationException::withMessages(['otp' => 'Too many incorrect attempts. Please request a new OTP.']);
        }

        if (! hash_equals($record->otp_hash, $this->hash($mobile, trim($code)))) {
            $record->increment('attempts');
            throw ValidationException::withMessages(['otp' => 'The OTP is incorrect.']);
        }

        $record->update(['verified_at' => now()]);

        return $record;
    }

    /**
     * @deprecated Use PhoneNumberService::normalize() directly.
     */
    public function normalizeMobile(string $mobile): string
    {
        return $this->phones->normalize($mobile);
    }

    private function hash(string $mobile, string $code): string
    {
        return hash_hmac('sha256', $mobile.'|'.$code, (string) config('app.key'));
    }
}
