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
    'max_trace_bytes' => 3 * 1024 * 1024,   // raw tracer output accepted for parsing
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

    // Optional AI Redraw (OpenAI image editing): the picture is redrawn as clean black-and-white
    // artwork, which is then traced by the normal pipeline. Enabled only when a key is set.
    // The key never leaves the server; a picture is sent only when a user clicks the button.
    'ai' => [
        'openai_api_key' => env('OPENAI_API_KEY'),
        'image_model' => env('PICTOSVG_AI_IMAGE_MODEL', 'gpt-image-2.6-sunburst'),
        'image_quality' => env('PICTOSVG_AI_IMAGE_QUALITY', 'medium'),
        // Pinned explicitly so an ambient OPENAI_BASE_URL can never redirect the key or the picture.
        'base_url' => env('PICTOSVG_AI_BASE_URL', 'https://api.openai.com/v1'),
        'timeout' => (int) env('PICTOSVG_AI_TIMEOUT', 150),
        'max_response_bytes' => 40 * 1024 * 1024,
    ],
];
