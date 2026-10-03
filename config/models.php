<?php

return [
    // Which ModelProvider implementation to use: "mock" or "meshy".
    'provider' => env('MODEL_PROVIDER', 'mock'),

    // A creation still unfinished after this long is failed and refunded.
    'timeout_seconds' => 600,

    // How long the job waits between provider status checks.
    'poll_seconds' => 3,

    'meshy' => [
        // Total time budget per HTTP call (Guzzle's timeout covers the whole request). A
        // successful GenerateCreation run makes one status call plus three downloads, so the
        // worst case is 20 + 3 x 30 = 110 s, which stays under the job's 120 s worker timeout.
        'timeout_seconds' => 20,
        'download_timeout_seconds' => 30,
        'default_polycount' => 30000,
        // Meshy accepts 1,000 to 100,000 faces.
        'min_polycount' => 1000,
        'max_polycount' => 100000,
        // "standard", "2k" or "4k" (finest surface detail; +5 Meshy credits per build). Needs
        // MESHY_MODEL=meshy-7.1 or latest. Unset sends nothing.
        'geometry_resolution' => env('MESHY_GEOMETRY_RESOLUTION'),
    ],

    'mock' => [
        'delay_seconds' => (int) env('MODEL_MOCK_DELAY_SECONDS', 6),
        'fail_rate' => (float) env('MODEL_MOCK_FAIL_RATE', 0),
    ],
];
