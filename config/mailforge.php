<?php

return [

    /*
    |--------------------------------------------------------------------------
    | MailForge General Configuration
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'email_mode' => env('MAILFORGE_DEFAULT_EMAIL_MODE', 'direct'),
        'rate_limit_per_minute' => (int) env('MAILFORGE_RATE_LIMIT_PER_MINUTE', 120),
        'batch_size' => (int) env('MAILFORGE_BATCH_SIZE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers Configuration
    |--------------------------------------------------------------------------
    */
    'ai' => [
        'default_provider' => env('MAIL_FORGE_AI_PROVIDER', env('AI_PROVIDER', 'openai')),
        'max_tokens' => (int) env('AI_MAX_TOKENS', 1500),
        'temperature' => (float) env('AI_TEMPERATURE', 0.7),
        'providers' => [
            'openai' => [
                'api_key' => env('OPENAI_API_KEY'),
                'organization' => env('OPENAI_ORGANIZATION'),
                'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
                'timeout' => (int) env('OPENAI_TIMEOUT', 30),
            ],
            'gemini' => [
                'api_key' => env('GEMINI_API_KEY'),
                'model' => env('GEMINI_MODEL', 'gemini-3.5-flash'),
                'timeout' => (int) env('GEMINI_TIMEOUT', 30),
            ],
            'mock' => [
                'model' => 'mock-copywriter-v1',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Contact Spreadsheet Import Settings
    |--------------------------------------------------------------------------
    */
    'import' => [
        'max_file_size_kb' => (int) env('CONTACT_IMPORT_MAX_SIZE_KB', 10240),
        'allowed_extensions' => ['xlsx', 'xls', 'csv'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Email Delivery Settings
    |--------------------------------------------------------------------------
    */
    'delivery' => [
        'default_mailer' => env('MAIL_MAILER', 'smtp'),
        'rate_limit_per_minute' => (int) env('MAILFORGE_SEND_RATE_PER_MINUTE', 120),
        'chunk_size' => (int) env('MAILFORGE_SEND_CHUNK_SIZE', 100),
        'max_retry_attempts' => (int) env('MAILFORGE_MAX_RETRIES', 3),
        'processing_lease_seconds' => (int) env('MAILFORGE_PROCESSING_LEASE_SECONDS', 300),
        'retry_backoff_seconds' => [30, 120, 600],
    ],

];
