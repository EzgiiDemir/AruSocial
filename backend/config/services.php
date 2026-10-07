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

    /*
     * Directions over two OSRM graphs, both built from the same Cyprus OSM
     * extract (deploy/osrm/ builds and serves them).
     *
     * An OSRM instance serves ONE profile, and answers /route/v1/driving/
     * from a pedestrian graph without complaint — so the graph is chosen
     * by HOST, not by the profile in the path:
     *
     *   base_url          foot.lua graph  → walking     (osrm-foot:5000)
     *   driving_base_url  car.lua graph   → car / bus   (osrm-car:5000)
     *
     * Either being empty means that mode is not configured: the API
     * returns 501 ROUTING_NOT_CONFIGURED and the app falls back to an
     * honest straight-line estimate. A vehicle never silently borrows the
     * pedestrian graph — that produced real-looking routes down stairs
     * and footpaths no car can use.
     */
    'routing' => [
        'base_url' => env('ROUTING_BASE_URL'),
        'driving_base_url' => env('ROUTING_DRIVING_BASE_URL'),
        // A public third-party OSRM must not be required. Falling back to the
        // public demo server is opt-in and OFF by default: a self-hosted OSRM
        // that is down yields an honest 501, never a silent external call.
        'allow_public_fallback' => filter_var(env('ROUTING_ALLOW_PUBLIC_FALLBACK', 'false'), FILTER_VALIDATE_BOOLEAN),
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

    /*
     * Live map presence (App\Services\LiveCrowd).
     *
     * How close a location ping has to be to a building before it counts
     * as "this person is here". Tighter than the check-in radius on
     * purpose: a check-in is deliberate and forgiving of a poor GPS fix,
     * while a passive ping that lands 150 m away would credit a crowd to
     * the wrong building.
     */
    'presence' => [
        'radius_meters' => (float) env('PRESENCE_RADIUS_METERS', 75),
    ],

    /*
     * What a phone is allowed to upload.
     *
     * Deliberately a short allowlist rather than a blocklist: every format
     * here is one the app can actually display, and anything else is
     * refused by name instead of being stored and discovered later. The
     * limits are sized for a normal phone photo or a short clip — big
     * enough that ordinary use never hits them, small enough that a single
     * upload cannot fill the disk or stall moderation.
     */
    'media_uploads' => [
        // Images only. Video was removed from the product on 14 September
        // 2026; MediaController answers an upload of one with an explicit
        // VIDEO_NOT_SUPPORTED rather than a generic type error, because
        // older builds of the app still have the button.
        'image_mimes' => ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'],
        'max_image_bytes' => (int) env('MEDIA_MAX_IMAGE_BYTES', 12 * 1024 * 1024),
        // Beyond this a still is almost certainly a scan or a screenshot of
        // something else, and it costs real time to inspect.
        'max_image_pixels' => (int) env('MEDIA_MAX_IMAGE_PIXELS', 50_000_000),
    ],

    // ARUCAD-operated semantic image/video model. This is a local executable
    // path, never a vendor API key or URL. With no model installed, magic-byte
    // clean uploads are approved; a configured-but-unavailable model fails closed.
    'local_moderation' => [
        'binary' => env('LOCAL_MODERATION_BINARY'),
        'block_threshold' => (float) env('LOCAL_MODERATION_BLOCK_THRESHOLD', .92),
        'timeout_seconds' => (int) env('LOCAL_MODERATION_TIMEOUT_SECONDS', 15),
    ],

    /*
     * Central content moderation. OpenAI's dedicated moderation endpoint is
     * the primary layer (free, text + image, multilingual); the local
     * TextPolicyEngine runs alongside it for the things a general model is
     * weak at — deliberate obfuscation, spam shapes and campus-specific
     * policy. The key is server-side only and never reaches the app.
     */
    'moderation' => [
        // OPENAI_MODERATION_API_KEY is accepted as a fallback because the
        // example file documented that name for a while: an install that
        // copied it set a key the config never read, and the only symptom
        // was images quietly sitting in the review queue forever. Reading
        // both means an existing deployment keeps working; the canonical
        // name wins when both are present.
        'openai_key' => env('OPENAI_API_KEY') ?: env('OPENAI_MODERATION_API_KEY'),
        /*
         * OFF by default. Moderation is self-hosted.
         *
         * This defaulted to `true`, which meant a deployment that simply
         * did not set the variable would start sending student posts and
         * uploads to a third party — while the privacy notice states that
         * content is never sent outside ARUCAD for moderation, and while
         * the self-hosted classifiers do the work.
         *
         * A privacy claim that depends on an env var being remembered is
         * not a privacy claim. Turning this on is now a deliberate act,
         * and it needs the notice updated with it.
         */
        'enabled' => filter_var(env('MODERATION_OPENAI_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN),
        'model' => env('OPENAI_MODERATION_MODEL', env('MODERATION_MODEL', 'omni-moderation-latest')),
        'endpoint' => env('MODERATION_ENDPOINT', 'https://api.openai.com/v1/moderations'),
        'timeout_seconds' => (int) env('MODERATION_TIMEOUT_SECONDS', 12),

        /*
         * Fail closed. If the provider is unreachable we must not publish
         * unchecked content, so submissions are held for human review
         * instead of being silently let through.
         */
        'fail_closed' => filter_var(env('MODERATION_FAIL_CLOSED', 'true'), FILTER_VALIDATE_BOOLEAN),

        /*
         * Per-category HIGH-confidence score thresholds. Lower scores are
         * evidence for contextual review, never an automatic removal.
         */
        'thresholds' => [
            'sexual/minors' => (float) env('MODERATION_T_SEXUAL_MINORS', .90),
            'harassment/threatening' => (float) env('MODERATION_T_HARASSMENT_THREAT', .85),
            'hate/threatening' => (float) env('MODERATION_T_HATE_THREAT', .85),
            'violence/graphic' => (float) env('MODERATION_T_VIOLENCE_GRAPHIC', .90),
            'self-harm/instructions' => (float) env('MODERATION_T_SELF_HARM_INSTR', .90),
            'self-harm/intent' => (float) env('MODERATION_T_SELF_HARM_INTENT', .90),
            'sexual' => (float) env('MODERATION_T_SEXUAL', .90),
            'hate' => (float) env('MODERATION_T_HATE', .85),
            'harassment' => (float) env('MODERATION_T_HARASSMENT', .90),
            'violence' => (float) env('MODERATION_T_VIOLENCE', .90),
            'self-harm' => (float) env('MODERATION_T_SELF_HARM', .90),
            'illicit' => (float) env('MODERATION_T_ILLICIT', .90),
            'illicit/violent' => (float) env('MODERATION_T_ILLICIT_VIOLENT', .90),
        ],

        /*
         * The strike ladder that used to live here is gone. What a
         * violation costs is now one points ladder shared by every path,
         * in config/moderation.php under `enforcement`.
         */

        /* Privacy: keep the offending text only long enough to appeal. */
        'retain_excerpt_days' => (int) env('MODERATION_RETAIN_EXCERPT_DAYS', 30),
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

    // Vendor-neutral SIS boundary. No production provider is enabled until
    // ARUCAD IT supplies an approved read-only integration and identity map.
    'sis' => [
        'provider' => env('SIS_PROVIDER', 'unavailable'),
        'identity_field' => env('SIS_IDENTITY_FIELD', 'email'),
        'cache_seconds' => (int) env('SIS_CACHE_SECONDS', 300),
    ],

];
