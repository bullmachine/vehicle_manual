<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdminAuthController extends Controller
{
    public function __construct(private readonly TwoFactorService $twoFactor)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string', 'max:160'],
            'password' => ['required', 'string', 'max:255'],
            'otp' => ['nullable', 'digits:6'],
        ]);
        $key = 'admin-login:'.Str::lower($validated['login']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 8)) {
            throw ValidationException::withMessages(['login' => 'Too many login attempts. Try again later.']);
        }

        $user = User::query()->where('is_active', true)->where(function ($query) use ($validated) {
            $query->where('email', $validated['login'])->orWhere('username', $validated['login']);
        })->with('role')->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($key, 900);
            throw ValidationException::withMessages(['login' => 'The supplied credentials are incorrect.']);
        }

        if ($user->two_factor_confirmed_at) {
            if (! $validated['otp'] || ! $this->twoFactor->verify($user, $validated['otp'])) {
                return response()->json(['message' => 'Two-factor verification is required.', 'two_factor_required' => true], 202);
            }
        }

        RateLimiter::clear($key);
        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('admin-panel', ['admin'])->plainTextToken;

        return response()->json(['token' => $token, 'user' => $this->userData($user)]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->userData($request->user())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => 'Signed out.']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => $validated['email']]);

        return response()->json(['message' => 'If that account exists, a reset link has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        $status = Password::reset($validated, function (User $user, string $password) {
            $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => 'The reset link is invalid or expired.']);
        }

        return response()->json(['message' => 'Password updated.']);
    }

    public function enableTwoFactor(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->twoFactor->enable($request->user())]);
    }

    public function confirmTwoFactor(Request $request): JsonResponse
    {
        $validated = $request->validate(['otp' => ['required', 'digits:6']]);
        abort_unless($this->twoFactor->confirm($request->user(), $validated['otp']), 422, 'The OTP is incorrect.');

        return response()->json(['message' => 'Two-factor authentication enabled.']);
    }

    private function userData(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'username' => $user->username,
            'role' => $user->role?->only(['id', 'name', 'slug']),
            'two_factor_enabled' => (bool) $user->two_factor_confirmed_at,
        ];
    }
}
