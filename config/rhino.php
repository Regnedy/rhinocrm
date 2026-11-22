<?php

return [
    'version' => env('RHINO_VERSION', '2026.08.08-whatsapp-embedded-signup.1'),
    'installed_lock' => storage_path('app/installed.lock'),
    'auth' => [
        'driver' => env('RHINO_AUTH_DRIVER', 'local'), // local, firebase, hybrid
        'session_days' => (int) env('RHINO_SESSION_DAYS', 30),
        'touch_interval_seconds' => (int) env('RHINO_SESSION_TOUCH_SECONDS', 300),
    ],
    'mobile' => [
        'require_active_campaign' => filter_var(env('RHINO_MOBILE_REQUIRE_ACTIVE_CAMPAIGN', true), FILTER_VALIDATE_BOOL),
    ],
    'region' => [
        'default_country_iso' => env('RHINO_DEFAULT_COUNTRY_ISO', 'MX'),
        'default_country_dial_code' => preg_replace('/\D+/', '', (string) env('RHINO_DEFAULT_COUNTRY_DIAL_CODE', '52')) ?: '52',
    ],
    'reports' => [
        'disk' => env('RHINO_REPORT_DISK', 'local'),
        'directory' => env('RHINO_REPORT_DIRECTORY', 'reports'),
        'download_minutes' => (int) env('RHINO_REPORT_DOWNLOAD_MINUTES', 15),
    ],
    'personal_reports' => [
        'disk' => env('RHINO_PERSONAL_REPORT_DISK', 'local'),
        'directory' => env('RHINO_PERSONAL_REPORT_DIRECTORY', 'personal-reports'),
        'algorithm_version' => env('RHINO_PERSONAL_REPORT_ALGORITHM', 'rhino-nutrition-engine-v2'),
        'nutrition_algorithm_version' => env('RHINO_NUTRITION_ALGORITHM', 'rhino-nutrition-engine-v2'),
        'whatsapp_business_phone' => env('RHINO_WHATSAPP_BUSINESS_PHONE'),
        'whatsapp_contact_name' => env('RHINO_WHATSAPP_CONTACT_NAME', 'RHINO CRM'),
        'whatsapp_window_hours' => (int) env('RHINO_WHATSAPP_WINDOW_HOURS', 24),
        'whatsapp_template' => env('RHINO_WHATSAPP_REPORT_TEMPLATE'),
        'whatsapp_template_language' => env('RHINO_WHATSAPP_REPORT_TEMPLATE_LANGUAGE', 'es_MX'),
        'disclaimer' => env('RHINO_PERSONAL_REPORT_DISCLAIMER', 'Las mediciones y la alimentación recomendada son orientativas. No constituyen diagnóstico ni prescripción clínica y no sustituyen consulta médica o nutricional profesional.'),
    ],
    'nutrition_engine' => [
        'algorithm_version' => env('RHINO_NUTRITION_ALGORITHM', 'rhino-nutrition-engine-v2'),
        'library_version' => env('RHINO_NUTRITION_LIBRARY_VERSION', '2026.08.08-fitdoctor8-ref-v2'),
        'default_meals_per_day' => (int) env('RHINO_NUTRITION_DEFAULT_MEALS_PER_DAY', 4),
        'ai_mode' => env('RHINO_NUTRITION_AI_MODE', 'MANUAL_JSON'),
        'pdf_disk' => env('RHINO_NUTRITION_PDF_DISK', 'local'),
        'pdf_directory' => env('RHINO_NUTRITION_PDF_DIRECTORY', 'nutrition-plans'),
        'image_dpi' => (int) env('RHINO_NUTRITION_IMAGE_DPI', 120),
        'image_quality' => (int) env('RHINO_NUTRITION_IMAGE_QUALITY', 88),
        'quick_plan_version' => env('RHINO_NUTRITION_QUICK_PLAN_VERSION', 'fitdoctor8-kiosk-v1'),
        'whatsapp_template' => env('RHINO_WHATSAPP_NUTRITION_PLAN_TEMPLATE'),
        'whatsapp_pdf_template' => env('RHINO_WHATSAPP_NUTRITION_PLAN_TEMPLATE'),
        'whatsapp_images_template' => env('RHINO_WHATSAPP_NUTRITION_IMAGES_TEMPLATE'),
        'whatsapp_template_language' => env('RHINO_WHATSAPP_NUTRITION_PLAN_LANGUAGE', 'es_MX'),
        'whatsapp_images_template_language' => env('RHINO_WHATSAPP_NUTRITION_IMAGES_LANGUAGE', 'es_MX'),
    ],
    'nutrition_campaigns' => [
        'default_days_since_plan' => (int) env('RHINO_NUTRITION_CAMPAIGN_DAYS_SINCE_PLAN', 30),
        'whatsapp_window_hours' => (int) env('RHINO_WHATSAPP_WINDOW_HOURS', 24),
        'whatsapp_template' => env('RHINO_WHATSAPP_NUTRITION_INVITATION_TEMPLATE'),
        'whatsapp_template_language' => env('RHINO_WHATSAPP_NUTRITION_INVITATION_LANGUAGE', 'es_MX'),
    ],
    'installer' => [
        'enabled' => env('RHINO_INSTALLER_ENABLED', true),
    ],
];
