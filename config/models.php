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
        // worst case is 20 + 3 x 12 = 56 s, which stays under the job's 60 s worker timeout.
        'timeout_seconds' => 20,
        'download_timeout_seconds' => 12,
        'default_polycount' => 30000,
    ],

    'mock' => [
        'delay_seconds' => (int) env('MODEL_MOCK_DELAY_SECONDS', 6),
        'fail_rate' => (float) env('MODEL_MOCK_FAIL_RATE', 0),
    ],
];
