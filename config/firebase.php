<?php

$projectId = env('FIREBASE_PROJECT_ID');

return [
    'auth_enabled' => filter_var(env('FIREBASE_AUTH_ENABLED', false), FILTER_VALIDATE_BOOL),
    'project_id' => $projectId,
    'credentials' => env('FIREBASE_CREDENTIALS'),
    'require_email_verified' => filter_var(env('FIREBASE_REQUIRE_EMAIL_VERIFIED', true), FILTER_VALIDATE_BOOL),
    'auto_link_by_email' => filter_var(env('FIREBASE_AUTO_LINK_BY_EMAIL', true), FILTER_VALIDATE_BOOL),
    'check_revoked_tokens' => filter_var(env('FIREBASE_CHECK_REVOKED_TOKENS', true), FILTER_VALIDATE_BOOL),
    'allow_direct_api_tokens' => filter_var(env('FIREBASE_ALLOW_DIRECT_API_TOKENS', false), FILTER_VALIDATE_BOOL),
    'sync_custom_claims_on_login' => filter_var(env('FIREBASE_SYNC_CUSTOM_CLAIMS_ON_LOGIN', true), FILTER_VALIDATE_BOOL),
    'auto_send_verification' => filter_var(env('FIREBASE_AUTO_SEND_VERIFICATION', true), FILTER_VALIDATE_BOOL),
    'verification_continue_url' => env('FIREBASE_VERIFICATION_CONTINUE_URL', env('APP_URL')),

    // Configuración pública de la aplicación web. Estos identificadores no son
    // la cuenta de servicio y pueden enviarse al navegador.
    'web' => [
        'google_enabled' => filter_var(env('FIREBASE_WEB_GOOGLE_ENABLED', false), FILTER_VALIDATE_BOOL),
        'google_client_id' => env('FIREBASE_WEB_GOOGLE_CLIENT_ID'),
        'api_key' => env('FIREBASE_WEB_API_KEY'),
        'auth_domain' => env('FIREBASE_WEB_AUTH_DOMAIN', $projectId ? $projectId.'.firebaseapp.com' : null),
        'app_id' => env('FIREBASE_WEB_APP_ID'),
        'messaging_sender_id' => env('FIREBASE_WEB_MESSAGING_SENDER_ID'),
        'storage_bucket' => env('FIREBASE_WEB_STORAGE_BUCKET'),
    ],

    'emulator' => [
        'enabled' => filter_var(env('FIREBASE_EMULATOR_ENABLED', false), FILTER_VALIDATE_BOOL),
        'auth_host' => env('FIREBASE_AUTH_EMULATOR_HOST', '127.0.0.1:9099'),
    ],
];
