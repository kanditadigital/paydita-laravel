<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Connection
    |--------------------------------------------------------------------------
    |
    | The address of your Paydita server (host only, no path) and the three
    | secrets issued for this application and environment. Keep them in your
    | backend environment only: never in JavaScript, a mobile app or a repository.
    |
    */

    'base_url' => env('PAYDITA_BASE_URL'),
    'key' => env('PAYDITA_KEY'),
    'secret' => env('PAYDITA_SECRET'),
    'signing_secret' => env('PAYDITA_SIGNING_SECRET'),

    'timeout' => (int) env('PAYDITA_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Events from Paydita
    |--------------------------------------------------------------------------
    |
    | The package registers a POST route that verifies the signature of each
    | event and dispatches a Laravel event (PaymentPaid, PaymentExpired, ...).
    | Register the full URL as the application's callback URL in Paydita.
    |
    */

    'webhook' => [
        'enabled' => true,
        'path' => env('PAYDITA_WEBHOOK_PATH', 'paydita/events'),
        'middleware' => [],
        'tolerance_seconds' => 300,
        'remember_event_ids_for_seconds' => 7 * 24 * 3600,
    ],

];
