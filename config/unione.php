<?php

return [
    // User or project API key.
    'api_key' => env('UNIONE_API_KEY'),

    // API host (datacenter: api.unione.io, eu1.unione.io, us1.unione.io) or a full base URL; null: https://api.unione.io/en/transactional/api/v1.
    'host' => env('UNIONE_HOST'),

    // Request timeout, seconds.
    'timeout' => (float)env('UNIONE_TIMEOUT', 30),

    'webhook' => [
        // Register the route of the webhook endpoint (GET: URL check, POST: notifications).
        'enabled' => (bool)env('UNIONE_WEBHOOK_ENABLED', true),
        'path' => env('UNIONE_WEBHOOK_PATH', '/unione/webhook'),
        // API key the notifications are signed with, null: api_key.
        'key' => env('UNIONE_WEBHOOK_KEY'),
        'middleware' => [],
    ],
];
