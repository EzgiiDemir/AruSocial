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
        'policy_version' => env('IMAGE_POLICY_VERSION', 'image-v2-calibrated'),

        /*
         * Per-category thresholds. Semantics:
         *
         *   score <  review              -> ALLOW
         *   review <= score < block      -> REVIEW  (held, no strike)
         *   score >= block               -> BLOCK
         *
         * CALIBRATED against our own labelled set — see
         * image-moderation-service/benchmark.py and
         * benchmark_manifest.json. Measured separation on that set:
         *
         *   safe    n=49   highest score 0.0663
         *   unsafe  n=21   lowest  score 0.6715
         *
         * The two populations do not overlap, and they do not come close:
         * there is an empty band roughly ten times wider than the entire
         * spread of the safe set. These values sit inside it with margin
         * on both sides — 3x above the highest safe score, and 0.17 below
         * the lowest unsafe one.
         *
         * The previous 0.85 block was wrong in a way that mattered: real
         * photographic nudity scores ~0.67, so it was *held for review*
         * rather than blocked. Held content waits on a moderator, and an
         * unstaffed queue means it waits indefinitely.
         *
         * The safe set deliberately includes the app's Rodin sculpture
         * series (`03-falling-man`, `05-eve`, `07-eternal-spring`,
         * `11-the-kiss`). ARUCAD is an art and design university, so
         * sculpture and figure work are ordinary coursework here. Those
         * files score 0.003-0.006 — the model separates bronze from
         * photography cleanly, which is the single result that makes it
         * usable at this institution.
         *
         * HONEST LIMIT: the unsafe set is 21 files but only 2 distinct
         * images; the rest are duplicate uploads. Two images cannot
         * establish a recall figure. These thresholds are defensible and
         * measured, not statistically validated — widen the set before
         * treating any accuracy number as real.
         *
         * Categories are matched against the labels the model actually
         * returns, lowercased. Unknown labels are ignored rather than
         * guessed at.
         */
        'thresholds' => [
            'nsfw' => [
                'review' => (float) env('IMAGE_NSFW_REVIEW_THRESHOLD', 0.20),
                'block' => (float) env('IMAGE_NSFW_BLOCK_THRESHOLD', 0.50),
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
