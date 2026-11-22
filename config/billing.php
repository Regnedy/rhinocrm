<?php

return [
    'enabled' => (bool) env('BILLING_ENABLED', false),
    'enforcement_enabled' => (bool) env('SUBSCRIPTION_ENFORCEMENT_ENABLED', false),
    'default_plan_code' => env('BILLING_DEFAULT_PLAN_CODE', 'rhino_crm_access'),
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 3),
    'providers' => [
        'stripe' => ['enabled' => (bool) env('STRIPE_BILLING_ENABLED', false)],
        'google_play' => ['enabled' => (bool) env('GOOGLE_PLAY_BILLING_ENABLED', false)],
        'apple' => ['enabled' => (bool) env('APPLE_BILLING_ENABLED', false)],
    ],
    'mobile' => [
        'minimum_supported_version' => env('RHINO_MOBILE_MIN_VERSION', '1.0.0'),
        'latest_version' => env('RHINO_MOBILE_LATEST_VERSION', '1.0.0'),
        'maintenance_mode' => (bool) env('RHINO_MOBILE_MAINTENANCE', false),
        'support_email' => env('RHINO_MOBILE_SUPPORT_EMAIL', 'soporte@rhinocrm.app'),
    ],
];
