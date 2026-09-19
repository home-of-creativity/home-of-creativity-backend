<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'ffmpeg' => [
        'binary' => env('FFMPEG_BINARY', 'ffmpeg'),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'n8n' => [
        'webhook_url' => env('N8N_WEBHOOK_URL'),
        'webhook_secret' => env('N8N_WEBHOOK_SECRET'),
        'timeout' => (int) env('N8N_TIMEOUT', 12),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'staff_chat_id' => env('TELEGRAM_STAFF_CHAT_ID'),
        'bot_secret' => env('TELEGRAM_BOT_SECRET'),
        'bot_username' => env('TELEGRAM_BOT_USERNAME', 'pro_design_perfect_bot'),
        'staff_bot_token' => env('TELEGRAM_STAFF_BOT_TOKEN'),
        'staff_bot_secret' => env('TELEGRAM_STAFF_BOT_SECRET', 'change-me-staff'),
        'staff_bot_username' => env('TELEGRAM_STAFF_BOT_USERNAME'),
        'admin_bot_token' => env('TELEGRAM_ADMIN_BOT_TOKEN'),
        'admin_bot_secret' => env('TELEGRAM_ADMIN_BOT_SECRET', 'change-me-admin'),
        'admin_telegram_ids' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TELEGRAM_ADMIN_IDS', '')),
        ))),
        'strict' => (bool) env('TELEGRAM_STRICT', false),
    ],

    'google' => [
        'credentials_json' => env('GOOGLE_CREDENTIALS_JSON', env('GOOGLE_SERVICE_ACCOUNT_JSON', env('GOOGLE_APPLICATION_CREDENTIALS'))),
        'drive_parent_folder_id' => env('GOOGLE_DRIVE_PARENT_FOLDER_ID'),
        'calendar_id' => env('GOOGLE_CALENDAR_ID', 'primary'),
        'search_site_url' => env('GOOGLE_SEARCH_SITE_URL', 'https://hoc.agency/'),
        'search_sitemap_url' => env('GOOGLE_SEARCH_SITEMAP_URL', 'https://hoc.agency/sitemap.xml'),
        'indexnow_key' => env('INDEXNOW_KEY', 'c4e8a91b7d2f40c6a5e13b8f0d9c276a'),
    ],

    'elevenlabs' => [
        'api_key' => env('ELEVENLABS_API_KEY'),
        'timeout' => (int) env('ELEVENLABS_TIMEOUT', 60),
        'voice_id' => env('ELEVENLABS_VOICE_ID'),
    ],

    'odoo' => [
        'enabled' => (bool) env('ODOO_ENABLED', false),
        'url' => env('ODOO_URL'),
        'db' => env('ODOO_DB'),
        'username' => env('ODOO_USERNAME'),
        'api_key' => env('ODOO_API_KEY'),
        'use_json2' => (bool) env('ODOO_USE_JSON2', false),
        'timeout' => (int) env('ODOO_TIMEOUT', 12),
        'currency_code' => env('ODOO_CURRENCY_CODE', 'SYP'),
    ],

    'clickup' => [
        'token' => env('CLICKUP_TOKEN'),
        'space_id' => env('CLICKUP_SPACE_ID'),
        'team_id' => env('CLICKUP_TEAM_ID'),
        'list_id' => env('CLICKUP_LIST_ID'),
        'lists' => [
            'sales' => env('CLICKUP_LIST_SALES', env('CLICKUP_LIST_ID')),
            'photography' => env('CLICKUP_LIST_PHOTOGRAPHY'),
            'content' => env('CLICKUP_LIST_CONTENT'),
            'design' => env('CLICKUP_LIST_DESIGN'),
            'programming' => env('CLICKUP_LIST_PROGRAMMING'),
        ],
        'timeout' => (int) env('CLICKUP_TIMEOUT', 12),
        'statuses' => [
            'progress' => env('CLICKUP_STATUS_PROGRESS', 'in progress'),
            'review' => env('CLICKUP_STATUS_REVIEW', 'review'),
            'complete' => env('CLICKUP_STATUS_COMPLETE', 'complete'),
            'cancelled' => env('CLICKUP_STATUS_CANCELLED', 'canceled'),
        ],
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY', env('GOOGLE_API_KEY')),
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        'model' => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'timeout' => (int) env('GEMINI_TIMEOUT', 30),
        'e2e_stub' => env('GEMINI_E2E_STUB', false),
    ],

    'google_translate' => [
        'enabled' => filter_var(env('GOOGLE_TRANSLATE_ENABLED', true), FILTER_VALIDATE_BOOL),
        'api_key' => env('GOOGLE_TRANSLATE_API_KEY', env('GOOGLE_API_KEY')),
        'timeout' => (int) env('GOOGLE_TRANSLATE_TIMEOUT', 12),
    ],

    'social' => [
        'graph_base' => env('META_GRAPH_URL', 'https://graph.facebook.com/v21.0'),
        'threads_graph_base' => env('THREADS_GRAPH_URL', 'https://graph.threads.net/v1.0'),
        'timeout' => (int) env('SOCIAL_GRAPH_TIMEOUT', 20),
        'connect_timeout' => (int) env('SOCIAL_GRAPH_CONNECT_TIMEOUT', 10),
        'upload_timeout' => (int) env('SOCIAL_GRAPH_UPLOAD_TIMEOUT', 180),
        'timezone' => env('SOCIAL_TIMEZONE', 'Asia/Damascus'),
        'landing_instagram_handle' => env('SOCIAL_LANDING_INSTAGRAM_HANDLE', 'homeofcreativity.sy'),
        'landing_facebook_page_id' => env('SOCIAL_LANDING_FACEBOOK_PAGE_ID', ''),
        'landing_facebook_handle' => env('SOCIAL_LANDING_FACEBOOK_HANDLE', 'homeofcreativity'),
    ],

    'facebook' => [
        'access_token' => trim((string) env('FACEBOOK_ACCESS_TOKEN', '')),
        'app_id' => trim((string) env('FACEBOOK_APP_ID', '')),
        'app_secret' => trim((string) env('FACEBOOK_APP_SECRET', '')),
    ],

    'threads' => [
        'access_token' => trim((string) env('THREADS_ACCESS_TOKEN', '')),
        'app_id' => trim((string) env('THREADS_APP_ID', '')),
        'app_secret' => trim((string) env('THREADS_APP_SECRET', '')),
        'redirect_uri' => trim((string) env('THREADS_REDIRECT_URI', 'https://hoc.agency/auth/threads/callback')),
        'dashboard_accounts_url' => trim((string) env('THREADS_DASHBOARD_URL', 'https://hoc.agency/dashboard/social/accounts')),
        'oauth_authorize' => env('THREADS_OAUTH_URL', 'https://threads.net/oauth/authorize'),
        'oauth_token_base' => env('THREADS_OAUTH_TOKEN_URL', 'https://graph.threads.net'),
        'scopes' => env('THREADS_OAUTH_SCOPES', 'threads_basic,threads_content_publish,threads_read_replies,threads_manage_replies'),
    ],

    'linkedin' => [
        'client_id' => trim((string) env('LINKEDIN_CLIENT_ID', '')),
        'client_secret' => trim((string) env('LINKEDIN_CLIENT_SECRET', '')),
        'redirect_uri' => trim((string) env('LINKEDIN_REDIRECT_URI', 'https://hoc.agency/auth/linkedin/callback')),
        'dashboard_accounts_url' => trim((string) env('LINKEDIN_DASHBOARD_URL', 'https://hoc.agency/dashboard/social/accounts')),
        'oauth_host' => env('LINKEDIN_OAUTH_HOST', 'https://www.linkedin.com/oauth/v2'),
        'api_base' => env('LINKEDIN_API_BASE', 'https://api.linkedin.com/v2'),
        'rest_base' => env('LINKEDIN_REST_BASE', 'https://api.linkedin.com/rest'),
        'api_version' => env('LINKEDIN_API_VERSION', '202409'),
        'scopes' => env('LINKEDIN_OAUTH_SCOPES', 'r_organization_social,w_organization_social,rw_organization_admin'),
    ],

];
