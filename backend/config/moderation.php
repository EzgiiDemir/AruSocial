<?php

return [
    /*
     * Self-hosted moderation gateway. Disabled installations keep the
     * existing local/legacy provider path; once enabled, this service is the
     * required central decision point for text, URL and uploaded media.
     */
    'enabled' => filter_var(env('MODERATION_SERVICE_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'base_url' => env('MODERATION_SERVICE_URL', 'http://moderation:8080'),
    'timeout' => (float) env('MODERATION_SERVICE_TIMEOUT', 15),

    /*
     * Visual moderation for uploaded images.
     *
     * The service is an internal, self-hosted classifier that returns
     * per-category scores and nothing else. Every decision about what a
     * score *means* is made here, in versioned configuration, because a
     * threshold is policy: it has to be auditable, explainable to a
     * student, and changeable without touching a model.
     */
    'image' => [
        'enabled' => filter_var(env('IMAGE_MODERATION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'base_url' => env('IMAGE_MODERATION_URL', 'http://127.0.0.1:8801'),
        'timeout' => (float) env('IMAGE_MODERATION_TIMEOUT', 20),

        /*
         * Stamped onto every decision. Change this whenever a threshold
         * below changes, or historical decisions become unexplainable:
         * "why was this blocked in March" has no answer if the numbers
         * that blocked it were silently replaced in April.
         */
        'policy_version' => env('IMAGE_POLICY_VERSION', 'image-v1'),

        /*
         * Per-category thresholds. Semantics:
         *
         *   score <  review              -> ALLOW
         *   review <= score < block      -> REVIEW  (held, no strike)
         *   score >= block               -> BLOCK
         *
         * PROVISIONAL VALUES. These are a deliberately cautious starting
         * point, not calibrated numbers — they were chosen before any
         * benchmark existed, and reviewing a few extra safe photos is a
         * far cheaper mistake than publishing one unsafe one. They must
         * be re-derived from our own labelled test set (see
         * `moderation:benchmark-images`) before launch; a model author's
         * reported accuracy says nothing about our campus photos.
         *
         * Categories are matched against the labels the model actually
         * returns, lowercased. Unknown labels are ignored rather than
         * guessed at.
         */
        'thresholds' => [
            'nsfw' => [
                'review' => (float) env('IMAGE_NSFW_REVIEW_THRESHOLD', 0.35),
                'block' => (float) env('IMAGE_NSFW_BLOCK_THRESHOLD', 0.85),
            ],
        ],

        /*
         * Fail-closed, and not negotiable for media.
         *
         * There is no local model that reads pixels, so "the scanner did
         * not answer" means nothing has looked at this image at all.
         * Publishing it would make the entire pipeline decorative. This
         * flag exists only so the intent is explicit and greppable — the
         * code does not offer a fail-open branch.
         */
        'fail_closed' => true,

        /*
         * Scan on the queue instead of during the upload request.
         *
         * Off by default, and that is a deliberate trade rather than an
         * oversight. Inference takes ~200ms, so synchronous costs the
         * student nothing today and buys a much better experience: they
         * find out immediately, and specifically, that a photo was
         * refused — instead of a vague "we are checking" followed later
         * by a notification.
         *
         * The cost of switching this on is that it needs a queue worker
         * running and supervised. Without one, every upload sits in
         * `pending` forever, which to a student is indistinguishable from
         * the uploader being broken — the exact complaint this whole
         * effort started from.
         *
         * Turn it on when upload volume makes a 200ms request cost real
         * (roughly tens of uploads a minute), and only once
         * `queue:work` is supervised and its backlog is monitored.
         */
        'async' => filter_var(env('IMAGE_MODERATION_ASYNC', false), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
     * User reporting. The limit is per account per hour — high enough
     * that nobody reporting in good faith will ever notice it, low
     * enough that flooding the queue stops being possible.
     */
    'reports' => [
        'rate_limit_per_hour' => (int) env('REPORTS_RATE_LIMIT_PER_HOUR', 20),
    ],
];
