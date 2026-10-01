<?php

return [
    // Which ModelProvider implementation to use. Only "mock" exists today.
    'provider' => env('MODEL_PROVIDER', 'mock'),

    // A creation still unfinished after this long is failed and refunded.
    'timeout_seconds' => 600,

    // How long the job waits between provider status checks.
    'poll_seconds' => 3,

    'mock' => [
        'delay_seconds' => (int) env('MODEL_MOCK_DELAY_SECONDS', 6),
        'fail_rate' => (float) env('MODEL_MOCK_FAIL_RATE', 0),
    ],
];
