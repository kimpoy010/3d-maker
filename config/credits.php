<?php

// The balance is stored as credits, where 1 credit = 1 peso. Customers only ever see pesos.
return [
    // Free balance granted at registration (enough for one preview, one build and the downloads).
    'signup' => (int) env('CREDITS_SIGNUP', 100),

    // Balance granted by the stubbed "Add balance" button.
    'topup' => (int) env('CREDITS_TOPUP', 100),

    // Charged for each restyle preview (and each retry of one).
    'restyle_cost' => (int) env('CREDITS_RESTYLE_COST', 25),

    // Charged once per creation to download its GLB and STL.
    'download_cost' => (int) env('CREDITS_DOWNLOAD_COST', 100),

    // The stub top-up is a free-credits button, so it is off in production by default.
    'stub_topup' => env('CREDITS_STUB_TOPUP', env('APP_ENV') !== 'production'),
];
