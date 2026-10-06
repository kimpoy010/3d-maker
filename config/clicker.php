<?php

return [
    // Saved designs per user.
    'max_per_user' => (int) env('CLICKER_MAX_PER_USER', 20),

    // Colours in one design.
    'max_colors' => 8,

    // The editor uploads a PNG; the server re-encodes it within these limits.
    'upload' => [
        'max_kb' => 10240,
        'min_edge' => 256,
        'max_edge' => 4096,
        'max_pixels' => 40_000_000,
    ],

    // The Node binary that runs resources/clicker/build.mjs.
    'node_binary' => env('CLICKER_NODE_BINARY', 'node'),

    // The build process is killed after this long (must stay below the job's 120 s timeout).
    'build_timeout_seconds' => (int) env('CLICKER_BUILD_TIMEOUT', 90),

    // A build still "building" after this long (plus a grace period) is failed and refunded by the sweeper.
    'build_deadline_seconds' => 300,
];
