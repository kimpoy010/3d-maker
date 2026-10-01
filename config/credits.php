<?php

return [
    // Free credits granted at registration.
    'signup' => (int) env('CREDITS_SIGNUP', 20),

    // Credits granted by the stubbed "Add credits" button.
    'topup' => (int) env('CREDITS_TOPUP', 25),

    // Credits charged for each restyle preview (and each retry of one).
    'restyle_cost' => (int) env('CREDITS_RESTYLE_COST', 1),

    // The stub top-up is a free-credits button, so it is off in production by default.
    'stub_topup' => env('CREDITS_STUB_TOPUP', env('APP_ENV') !== 'production'),
];
