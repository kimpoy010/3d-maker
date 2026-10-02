<?php

return [
    // Kill switch: false rejects new previews with a clear message.
    'enabled' => (bool) env('STYLIZER_ENABLED', true),

    // Which ImageStylizer to use: "mock" or "openai".
    'provider' => env('STYLIZER_PROVIDER', 'mock'),

    // A preview still unfinished after this long is failed and refunded.
    'job_deadline_seconds' => 300,

    // Unapproved previews are discarded after this many days.
    'retention_days' => (int) env('STYLIZER_RETENTION_DAYS', 7),

    // Maximum previews (including retries) one user may start per day.
    'daily_limit' => (int) env('STYLIZER_DAILY_LIMIT', 30),

    'openai' => [
        'model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-2.5-flare'),
        'quality' => env('OPENAI_IMAGE_QUALITY', 'medium'),
        'size' => env('OPENAI_IMAGE_SIZE', '1024x1536'),
        'input_fidelity' => env('OPENAI_INPUT_FIDELITY', 'low'),
        // JPEG keeps the response around 150 KB; a 2-3 MB PNG body can crawl on slower links and time out.
        'output_format' => env('OPENAI_OUTPUT_FORMAT', 'jpeg'),
        'output_compression' => (int) env('OPENAI_OUTPUT_COMPRESSION', 90),
        // Must stay below the RestylePhoto job timeout (150 s).
        'timeout_seconds' => 120,
    ],

    'mock' => [
        'fail_rate' => (float) env('STYLIZER_MOCK_FAIL_RATE', 0),
    ],
];
