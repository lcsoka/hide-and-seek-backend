<?php

return [
    // The API + the broadcasting auth endpoint are called cross-origin by the
    // Angular app (and later the mobile apps). Bearer-token auth, no cookies.
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => ['*'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,
];
