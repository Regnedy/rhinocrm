<?php

return [
    'meta' => [
        'api_url' => env('META_WHATSAPP_API_URL', 'https://graph.facebook.com/v25.0'),
        'app_id' => env('META_APP_ID'),
        'embedded_signup_config_id' => env('META_EMBEDDED_SIGNUP_CONFIG_ID'),
        'embedded_signup_enabled' => env('META_EMBEDDED_SIGNUP_ENABLED', false),
        'phone_number_id' => env('META_PHONE_NUMBER_ID'),
        'access_token' => env('META_ACCESS_TOKEN'),
        'webhook_verify_token' => env('META_WEBHOOK_VERIFY_TOKEN'),
        'app_secret' => env('META_APP_SECRET'),
    ],
];
