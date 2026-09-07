<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    // FCM HTTP v1. Placeholders only in .env.example — never commit a real
    // private key. Empty values bind NullFcmClient (inbox still persists).
    'fcm' => [
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'client_email' => env('FIREBASE_CLIENT_EMAIL'),
        'private_key' => env('FIREBASE_PRIVATE_KEY'),
        // Staging/production may set true once a Firebase project exists.
        // Empty credentials still bind NullFcmClient when this is false (P3-2).
        'required' => (bool) env('FCM_REQUIRED', false),
    ],

    // Walking directions (OSRM-compatible). Empty = not configured; the
    // API returns 501 ROUTING_NOT_CONFIGURED rather than inventing a route.
    'routing' => [
        'base_url' => env('ROUTING_BASE_URL'),
        // Local Windows often hits cURL 60 without a CA bundle. Default
        // off in local, on elsewhere. Override with ROUTING_VERIFY_SSL.
        'verify_ssl' => filter_var(
            env('ROUTING_VERIFY_SSL', env('APP_ENV') === 'local' ? 'false' : 'true'),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

    // ARUCAD's own 360° campus directory. This secret is server-only: the
    // Flutter app receives normalized place/directory data from our API and
    // never receives the Bearer token. Tours are bound onto verified Place
    // pins; upstream coords remain null.
    'campus_directory' => [
        'base_url' => env('CAMPUS_DIRECTORY_BASE_URL', 'https://360.arucad.edu.tr'),
        'api_key' => env('CAMPUS_DIRECTORY_API_KEY'),
        // Origin allowed to embed/open 360 tours from the app (iframe / WebView).
        'allowed_origin' => env('CAMPUS_DIRECTORY_ALLOWED_ORIGIN', 'https://360.arucad.edu.tr'),
        // Windows/PHP local installs may not have a CA bundle. Production
        // remains strict by default; only local development mirrors the
        // existing routing/Groq integration fallback.
        'verify_ssl' => filter_var(
            env('CAMPUS_DIRECTORY_VERIFY_SSL', env('APP_ENV') === 'local' ? 'false' : 'true'),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

    // Server-enforced check-in geofence. Client GPS is advisory only;
    // distance is always recomputed against place lat/lng.
    'checkin' => [
        'radius_meters' => (float) env('CHECKIN_RADIUS_METERS', 150),
        'campus_radius_meters' => (float) env('CHECKIN_CAMPUS_RADIUS_METERS', 3000),
        // polygon = site envelopes (default). radius = 3 km circles (legacy).
        'geofence_mode' => env('CHECKIN_GEOFENCE_MODE', 'polygon'),
        'cooldown_minutes' => (int) env('CHECKIN_COOLDOWN_MINUTES', 30),
    ],

    // Student feed posts go to pending_review when true. Production
    // defaults on; local/testing stay off so existing feed tests keep
    // asserting immediate visibility.
    'feed' => [
        'require_approval' => filter_var(
            env('FEED_REQUIRE_APPROVAL', env('APP_ENV') === 'production' ? 'true' : 'false'),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

    // ARUCAD-operated semantic image/video model. This is a local executable
    // path, never a vendor API key or URL. With no model installed, magic-byte
    // clean uploads are approved; a configured-but-unavailable model fails closed.
    'local_moderation' => [
        'binary' => env('LOCAL_MODERATION_BINARY'),
        'block_threshold' => (float) env('LOCAL_MODERATION_BLOCK_THRESHOLD', .92),
        'timeout_seconds' => (int) env('LOCAL_MODERATION_TIMEOUT_SECONDS', 15),
    ],

    // Microsoft Entra (public client + PKCE). Empty = GET /auth/entra/config
    // reports configured:false and POST /auth/entra returns 501.
    'entra' => [
        'tenant_id' => env('ENTRA_TENANT_ID'),
        'client_id' => env('ENTRA_CLIENT_ID'),
        'redirect_uri' => env('ENTRA_REDIRECT_URI'),
        'verify_ssl' => filter_var(
            env('ENTRA_VERIFY_SSL', env('APP_ENV') === 'local' ? 'false' : 'true'),
            FILTER_VALIDATE_BOOLEAN
        ),
    ],

    // Ask ARUCAD + related Groq calls. Default chat model is gpt-oss-20b
    // (available on the current Groq key). It is a reasoning model, so the
    // controller reads `content` and falls back to `reasoning` when content
    // is empty. Local Windows PHP frequently lacks a CA bundle (cURL 60) —
    // same default as routing.verify_ssl.
    'groq' => [
        'key' => env('GROQ_API_KEY'),
        'chat_model' => env('GROQ_CHAT_MODEL', 'openai/gpt-oss-20b'),
        // Poster → event draft is an explicit editorial-assist feature,
        // independent of local safety moderation.
        'vision_model' => env('GROQ_VISION_MODEL', 'meta-llama/llama-4-scout-17b-16e-instruct'),
        'verify_ssl' => filter_var(
            env('GROQ_VERIFY_SSL', env('APP_ENV') === 'local' ? 'false' : 'true'),
            FILTER_VALIDATE_BOOLEAN
        ),
        'rate_limit_per_minute' => (int) env('AI_RATE_LIMIT_PER_MINUTE', 20),
    ],

];
