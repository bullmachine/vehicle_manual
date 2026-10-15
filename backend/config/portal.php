<?php

return [
    'name' => env('APP_NAME', 'BULL Vehicle Manual Portal'),

    // Dummy machine records in the SAP API format, loaded by the seeder or `php artisan machines:sync --file=...`.
    'machine_data_path' => env('MACHINE_DATA_PATH', database_path('data/machines.json')),

    'customer_session_minutes' => (int) env('CUSTOMER_SESSION_MINUTES', 120),

    'phone' => [
        'default_region' => env('PHONE_DEFAULT_REGION', 'IN'),
        'allowed_regions' => array_values(array_filter(array_map(
            'strtoupper',
            array_map('trim', explode(',', (string) env('ALLOWED_PHONE_REGIONS', '')))
        ))),
    ],

    'manual_max_file_size' => (int) env('MANUAL_MAX_FILE_SIZE', 52428800),
    'pdf_renderer' => env('PDF_RENDERER', 'imagick'),
    'pdf_renderer_required' => filter_var(env('PDF_RENDERER_REQUIRED', false), FILTER_VALIDATE_BOOLEAN),
    'frontend_url' => env('FRONTEND_URL', env('APP_URL', 'http://localhost:5173')),
];
