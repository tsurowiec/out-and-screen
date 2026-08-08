<?php

return [
    /*
     * VAPID keys identify this server to the browser's push service. Generate a
     * pair with `php artisan webpush:vapid` and paste them into .env — the same
     * pair has to stay in place for the life of a subscription, because changing
     * it invalidates every device that has already subscribed.
     */
    'vapid' => [
        'subject' => env('VAPID_SUBJECT', env('APP_URL', 'http://localhost')),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    /*
     * How long a push may sit on the push service waiting for a device that is
     * offline. A "your time is up" message is worthless once it's stale, so this
     * is deliberately short.
     */
    'ttl' => 300,
];
