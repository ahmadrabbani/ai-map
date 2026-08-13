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

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'chat_model' => env('OPENAI_CHAT_MODEL', 'gpt-4o-mini'),
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'chat_model' => env('GEMINI_CHAT_MODEL', 'gemini-3-flash-preview'),
    ],

    'google' => [
        'maps_api_key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    'epermit_oracle' => [
        'enabled' => env('EPERMIT_ORACLE_ENABLED', false),
        'endpoint' => env('EPERMIT_ORACLE_ENDPOINT'),
        'timeout_seconds' => env('EPERMIT_ORACLE_TIMEOUT', 45),
        'category_id' => env('EPERMIT_ORACLE_CATEGORY_ID', 30),
        'sub_type_id' => env('EPERMIT_ORACLE_SUB_TYPE_ID', 15),
        'type_id' => env('EPERMIT_ORACLE_TYPE_ID', 1),
        'scheme_id' => env('EPERMIT_ORACLE_SCHEME_ID'),
        'phase_id' => env('EPERMIT_ORACLE_PHASE_ID'),
        'block_id' => env('EPERMIT_ORACLE_BLOCK_ID'),
        'commercial_type_id' => env('EPERMIT_ORACLE_COMMERCIAL_TYPE_ID'),
        'is_ebiz_objection' => env('EPERMIT_ORACLE_IS_EBIZ_OBJECTION', 0),
        'version' => env('EPERMIT_ORACLE_VERSION', 1),
        'login_id' => env('EPERMIT_ORACLE_LOGIN_ID', 31),
    ],

    'dfps' => [
        'endpoint' => env('DFPS_PUSH_ENDPOINT'),
        'username' => env('DFPS_USERNAME'),
        'password' => env('DFPS_PASSWORD'),
        'token' => env('DFPS_TOKEN'),
        'timeout' => env('DFPS_TIMEOUT', 60),
    ],

];
