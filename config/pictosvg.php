<?php

return [
    // External tools. Use absolute paths in production (PHP-FPM often has a minimal PATH).
    'potrace_bin' => env('PICTOSVG_POTRACE_BIN', 'potrace'),
    'magick_bin' => env('PICTOSVG_MAGICK_BIN', 'magick'),

    // Upload limits.
    'max_upload_mb' => (int) env('PICTOSVG_MAX_UPLOAD_MB', 20),
    'max_pixels' => (int) env('PICTOSVG_MAX_PIXELS', 40_000_000),
    'allowed_mimes' => ['image/jpeg' => 'jpeg', 'image/png' => 'png', 'image/webp' => 'webp'],

    // Temporary storage (never public). Each conversion gets its own directory.
    'storage_path' => env('PICTOSVG_STORAGE_PATH', storage_path('app/private/pictosvg')),
    'ttl_minutes' => (int) env('PICTOSVG_TTL_MINUTES', 60),
    'keep_versions' => 5,

    // Resource bounds for external processes.
    'process_timeout' => (int) env('PICTOSVG_PROCESS_TIMEOUT', 40),
    'magick_memory_limit' => env('PICTOSVG_MAGICK_MEMORY', '1GiB'),
    'magick_disk_limit' => env('PICTOSVG_MAGICK_DISK', '2GiB'),
    'max_working_side' => 3600,
    'max_svg_bytes' => 15 * 1024 * 1024,

    'defaults' => [
        'preset' => 'engrave',
        'detail' => 7,
        'smoothing' => 8,
        'noise' => 5,
        'smooth_curves' => true,
        'width' => 150,
        'unit' => 'mm',
    ],

    // Optional AI redraw. No provider adapter ships in the MVP; both values must be set
    // AND a provider adapter must exist for the feature to report as configured.
    'ai' => [
        'provider' => env('PICTOSVG_AI_PROVIDER'),
        'api_key' => env('PICTOSVG_AI_API_KEY'),
    ],
];
