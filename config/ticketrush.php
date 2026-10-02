<?php

return [
    'reservation_ttl_seconds' => (int) env('RESERVATION_TTL_SECONDS', 120),

    'payment' => [
        'url' => env('PAYMENT_MOCK_URL', 'http://payment-mock:8080'),
        'timeout_seconds' => (int) env('PAYMENT_TIMEOUT_SECONDS', 2),
        'retry_times' => (int) env('PAYMENT_RETRY_TIMES', 3),
    ],
];
