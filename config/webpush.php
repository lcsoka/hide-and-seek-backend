<?php

return [
    /*
     * VAPID keys for Web Push. Generate a keypair with `php artisan webpush:vapid` and paste the
     * two lines into your .env. When either key is empty, push is disabled and all sends no-op —
     * so the game works fine without it configured.
     */
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', env('APP_URL', 'https://hideandseek.hu')),
        'public_key' => env('VAPID_PUBLIC_KEY', ''),
        'private_key' => env('VAPID_PRIVATE_KEY', ''),
    ],

    // Content encoding negotiated by browsers today (older "aesgcm" is deprecated).
    'content_encoding' => 'aes128gcm',
];
