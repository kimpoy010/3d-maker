<?php

return [
    // Which ModelProvider implementation to use: "mock" or "meshy".
    'provider' => env('MODEL_PROVIDER', 'mock'),

    // A creation still unfinished after this long is failed and refunded.
    'timeout_seconds' => 600,

    // How long the job waits between provider status checks.
    'poll_seconds' => 3,

    'meshy' => [
        // Per HTTP call. Must stay well below the GenerateCreation job timeout (60 s), because
        // one run can download up to three files.
        'timeout_seconds' => 20,
        'default_polycount' => 30000,
    ],

    'mock' => [
        'delay_seconds' => (int) env('MODEL_MOCK_DELAY_SECONDS', 6),
        'fail_rate' => (float) env('MODEL_MOCK_FAIL_RATE', 0),
    ],
];
