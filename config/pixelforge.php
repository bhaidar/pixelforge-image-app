<?php

return [
    'api_key' => env('PIXELFORGE_API_KEY'),
    'webhook_secret' => env('PIXELFORGE_WEBHOOK_SECRET'),
    'base_url' => env('PIXELFORGE_BASE_URL', 'https://pixelforge.promptedit.dev/v1'),
    'webhook_tolerance_seconds' => 300,
    'starting_credit_nanos' => 5_000_000_000,
    'purchase_options_usd' => [5, 10, 25],
    'admin_emails' => array_filter(array_map('trim', explode(',', (string) env('ADMIN_EMAILS')))),
];
