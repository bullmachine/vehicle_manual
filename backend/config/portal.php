<?php

return [
    'name' => env('APP_NAME', 'BULL Vehicle Manual Portal'),

    'otp' => [
        'enabled' => filter_var(env('OTP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
        'length' => (int) env('OTP_LENGTH', 6),
        'expiry_minutes' => (int) env('OTP_EXPIRY_MINUTES', 5),
        'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 5),
        'resend_cooldown' => (int) env('OTP_RESEND_COOLDOWN', 30),
        'requests_per_window' => (int) env('OTP_REQUESTS_PER_WINDOW', 5),
        'window_minutes' => (int) env('OTP_WINDOW_MINUTES', 15),
        'provider' => env('OTP_PROVIDER', 'fake'),
    ],

    'customer_session_minutes' => (int) env('CUSTOMER_SESSION_MINUTES', 120),

    'phone' => [
        'default_region' => env('PHONE_DEFAULT_REGION', 'IN'),
        'allowed_regions' => array_values(array_filter(array_map(
            'strtoupper',
            array_map('trim', explode(',', (string) env('ALLOWED_PHONE_REGIONS', '')))
        ))),
    ],

    'manual_max_file_size' => (int) env('MANUAL_MAX_FILE_SIZE', 52428800),    'pdf_renderer' => env('PDF_RENDERER', 'imagick'),
    'pdf_renderer_required' => filter_var(env('PDF_RENDERER_REQUIRED', false), FILTER_VALIDATE_BOOLEAN),
    'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost:5173')),
];
