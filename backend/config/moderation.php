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
         *   safe    n=46   highest score 0.0663
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
         * MEASURED LIMIT — read this before trusting the numbers above.
         *
         * The set was widened on 10 September 2026 and the model was
         * caught missing two real student uploads: a dark-background nude
         * photograph scored 0.0090, and a phone photo of a monitor
         * displaying nudes scored 0.0005. Both were approved and
         * published before being found by eye.
         *
         * Both sit BELOW the safe set's own maximum of 0.0663, so the
         * populations overlap and no threshold separates them. Do not
         * attempt to fix this by lowering `block`: 0.009 would still miss
         * the 0.0005 case and would refuse much of the campus library.
         * It is a model capability limit, and only a second,
         * differently-trained signal addresses it.
         *
         * Corrected figures (51 safe, 23 unsafe): recall 0.913, two false
         * negatives, zero false positives. The thresholds below remain
         * correct for the content this model *can* see.
         *
         * Categories are matched against the labels the model actually
         * returns, lowercased. Unknown labels are ignored rather than
         * guessed at.
         */
        'thresholds' => [
            /*
             * RE-CALIBRATED 11 September 2026 against real everyday
             * photographs, after ordinary clothed people were held.
             *
             * The safe set used to calibrate 0.20 contained no
             * photographs of people in summer clothing at all — it was
             * buildings, sculptures, screenshots and logos. Real uploads
             * showed the distribution is wider than that:
             *
             *   t-shirt and denim shorts, rooftop      nsfw 0.221  <- held
             *   sports bra and running shorts          nsfw 0.116
             *   shirtless male selfie                  nsfw 0.016
             *   muay thai bout                         nsfw 0.000
             *   real nudity (what this model catches)  nsfw 0.60 - 0.98
             *
             * Review moves to 0.35: above every everyday photograph
             * measured, and still far below anything the model flags as
             * explicit. Block stays at 0.50 — nothing sits between 0.35
             * and 0.50 in any labelled set.
             *
             * Crop tops, bustiers, short shorts, short dresses and modest
             * cleavage are ordinary modern clothing and must publish. The
             * two nudes this model misses entirely (0.0090 and 0.0005)
             * are caught by clip_nudity, so raising this line costs no
             * recall.
             */
            'nsfw' => [
                'review' => (float) env('IMAGE_NSFW_REVIEW_THRESHOLD', 0.35),
                'block' => (float) env('IMAGE_NSFW_BLOCK_THRESHOLD', 0.50),
            ],

            /*
             * Second signal: CLIP scored against prompts. Categories are
             * evaluated independently and the strictest wins, so these
             * only ever make the pipeline stricter — they cannot rescue
             * an image the NSFW model already blocked.
             *
             * They exist because the NSFW model alone was measured
             * missing a dark-background nude (0.0090), a phone photo of a
             * monitor showing nudes (0.0005) and a gore image, all below
             * the safe set's own maximum.
             *
             * Measured separation on the 50-safe / 24-unsafe labelled set:
             *
             *   clip_nudity   safe max 0.3749   unsafe min 0.7383
             *   clip_gore     safe max 0.0339   true gore 0.7683
             *
             * Block sits at 0.60, inside both gaps with margin on either
             * side. Review at 0.45 is above every safe score and catches
             * anything approaching the band. At these values no campus
             * photograph in the set is held — including the Rodin nudes,
             * which is the result that makes this usable at an art
             * university and took sculpture-specific benign prompts to
             * achieve (see clip_classifier.py).
             */
            /*
             * People-in-photographs rules, re-calibrated 11 September
             * 2026 when kissing and swimwear were added to policy.
             *
             * The distinction these enforce is MEDIUM, not subject: a
             * photograph of real people kissing is refused; Rodin's *The
             * Kiss* is not. That is a real requirement here — the campus
             * is built around a nude sculpture collection and figure
             * drawing is a syllabus.
             *
             * Measured over the 74-image set, highest score on any safe
             * content vs lowest on real nudity:
             *
             *   12 artworks (The Kiss, Eternal Spring, Eternal Idol,
             *   Eve, Age of Bronze, ...)   max 0.2077   all allowed
             *   all other safe campus photos  max 0.3204
             *   real nudity                   min 0.5128   none published
             *
             * Block at 0.45 sits inside that gap with 0.13 of margin
             * below and 0.06 above. Review at 0.35 is above every safe
             * image, so nothing ordinary is held.
             *
             * One false positive was found and fixed on the way: an
             * ordinary selfie in a sleeveless top scored **0.6125** on
             * swimwear and would have been refused as a bikini photo.
             * Bare shoulders are not swimwear. The fix was naming
             * everyday clothing on the benign side of the prompts, not
             * moving this number — see clip_classifier.py.
             *
             * RECALL FOR kissing AND swimwear IS UNMEASURED. There are no
             * lawful photographs of either in this repository, so these
             * two are enabled on the false-positive evidence alone. Test
             * them with real examples before trusting them.
             */
            'clip_nudity' => [
                'review' => (float) env('IMAGE_CLIP_NUDITY_REVIEW', 0.35),
                'block' => (float) env('IMAGE_CLIP_NUDITY_BLOCK', 0.45),
            ],
            'clip_kissing' => [
                'review' => (float) env('IMAGE_CLIP_KISSING_REVIEW', 0.35),
                'block' => (float) env('IMAGE_CLIP_KISSING_BLOCK', 0.45),
            ],
            'clip_swimwear' => [
                'review' => (float) env('IMAGE_CLIP_SWIMWEAR_REVIEW', 0.35),
                'block' => (float) env('IMAGE_CLIP_SWIMWEAR_BLOCK', 0.45),
            ],
            'clip_gore' => [
                'review' => (float) env('IMAGE_CLIP_GORE_REVIEW', 0.45),
                'block' => (float) env('IMAGE_CLIP_GORE_BLOCK', 0.60),
            ],

            /*
             * DELIBERATELY ABSENT: clip_weapon, clip_hate_symbol,
             * clip_drugs, clip_self_harm.
             *
             * The service scores all four and they are recorded as
             * evidence, but there are no lawful positive examples for
             * them in this repository, so their recall is entirely
             * unmeasured. A threshold guessed without a positive example
             * is not a policy, it is a coin toss applied to students'
             * coursework — and the safe-set maxima (weapon 0.2104,
             * hate_symbol 0.2525, self_harm 0.3637) show these prompts
             * are noisy on ordinary photographs.
             *
             * To enable one: obtain lawful test images, add them to
             * benchmark_manifest.json, run probe_clip.py, and set the
             * threshold inside the measured gap. Until then reports and
             * the moderator queue are the honest mitigation.
             */
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
     * Self-hosted semantic text layer. Runs beside the deterministic
     * lexicon, not instead of it: the lexicon catches phrasings someone
     * wrote down, this catches rewording, and either can block alone.
     *
     * It replaced the remote provider because that account has no quota
     * and this project does not depend on a paid moderation API.
     *
     * Scores are signed MARGINS — how much more the text resembles a
     * category exemplar than ordinary campus writing — so a negative
     * value is normal and means "reads more like a campus post".
     *
     * CALIBRATED against image-moderation-service/text_benchmark.json
     * (19 harmful, 20 safe, TR/EN/RU). Measured:
     *
     *   harmful best margin   min -0.0182   median  0.3788
     *   safe    best margin   max -0.1295   median -0.4951
     *
     * RE-MEASURED against all 93 labelled texts (three sets, 43 blockable
     * harmful + 50 safe) with `calibrate_text_threshold.py`:
     *
     *   threshold   recall     false positives
     *      0.10     32/43          4/50
     *      0.12     29/43          2/50
     *      0.15     23/43          0/50   <- current
     *
     *   highest safe margin anywhere  +0.1336
     *   lowest  harmful margin        -0.0128
     *
     * The populations overlap, so there is no threshold that buys high
     * recall without refusing ordinary posts. 0.14 is the lowest
     * zero-false-positive value and gains exactly one case — it sits
     * 0.006 under the highest safe text in a 50-sample set, which is
     * tuning to noise. 0.15 keeps a little margin and stays here.
     *
     * THE CEILING: this layer catches roughly half of unseen harmful text
     * at zero false positives. More exemplars do not move it — two rounds
     * of adding them lifted the set they were written against and left a
     * fresh held-out set at 6/15. Raising recall further needs a real
     * model, not a longer list.
     *
     * The next honest gain is a REVIEW band (hold, don't refuse) over
     * roughly 0.10-0.15, which would catch 9 more cases while holding 4
     * safe posts instead of blocking them. It is deliberately not enabled
     * yet: held content needs a moderator queue screen to drain it, and
     * there is not one. Holding students' posts with nobody reading them
     * is worse than publishing.
     */
    'text' => [
        'enabled' => filter_var(env('TEXT_MODERATION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'base_url' => env('TEXT_MODERATION_URL', 'http://127.0.0.1:8801'),
        'timeout' => (float) env('TEXT_MODERATION_TIMEOUT', 10),

        /*
         * Hold coarse language even when it is aimed at nobody.
         *
         * OFF by default, and that default is a policy rather than an
         * oversight: a 150-case labelled corpus deliberately publishes
         * "What the fuck is wrong with this system?" and its Turkish and
         * Russian equivalents, because swearing at a broken app is a
         * student complaining, not a student attacking anyone.
         *
         * Turning it ON holds every such post for a moderator. There is
         * no version of this setting that catches "siktiğim ders" and
         * still publishes "what the fuck is wrong with this system" —
         * they are the same speech act, and anyone enabling it should
         * expect the queue to fill with complaints about the app.
         *
         * Abuse aimed at a person does not depend on this. Directed
         * imperatives, compound insults, family attacks and slurs are all
         * refused with it off.
         */
        'hold_untargeted_profanity' => filter_var(
            env('MODERATION_HOLD_UNTARGETED_PROFANITY', false), FILTER_VALIDATE_BOOLEAN,
        ),

        /*
         * `review` holds for a moderator; `block` refuses outright.
         *
         * The review band (0.10-0.15) is where the sweep showed 9 more
         * catches for 4 held safe posts out of 50. It was deliberately
         * left off until there was a screen to drain the queue, because
         * holding a student's post with nobody reading it is worse than
         * publishing it. That screen now exists (Admin → Moderation →
         * İnceleme kuyruğu), so the band is on.
         *
         * A held post is not a refused post: the author is told it is
         * being checked, no strike is recorded, and a human decides.
         * That is the right home for content the model finds ambiguous —
         * which, on the measured evidence, is most of what it sees.
         */
        'thresholds' => [
            /*
             * Per-category, calibrated from measured headroom rather than
             * one number applied to all of them.
             *
             * `per_category_threshold.py` reports, for each category, the
             * highest margin any SAFE text reaches and the lowest any
             * harmful one of that category reaches, over 94 labelled safe
             * texts and four sets. The two groups below came straight out
             * of that and they behave very differently.
             *
             * RE-CALIBRATED 11 September 2026, when scoring moved from
             * whole posts to clauses (see text_classifier.split_sentences).
             * Changing the unit that is scored changes every number here:
             * a clause is shorter than a post, so its margins are both
             * higher and noisier, and the previous ceilings were measured
             * against a unit that no longer exists. Leaving them would
             * have refused an exhibition announcement as DRUG.
             *
             * GROUP 1 — these five OVERLAP. Safe text scores as high as
             * harmful text, so no threshold separates them and lowering
             * one only buys false positives. Each review line now sits
             * just above its OWN measured safe ceiling, and the category
             * is carried mainly by the deterministic lexicon:
             *
             * Measured over all 152 labelled safe texts, the held-out sets
             * included — a ceiling taken from the tuning sets alone was
             * too low and held five ordinary messages ("Kütüphane
             * çıkışında buluşalım, notları vereyim sana" scored CYBER).
             *
             *            safe max   harmful min   set to
             *   HAR       0.1370      -0.0973     0.15 / 0.25
             *   HATE     -0.0118      -0.2471     0.02 / 0.12
             *   SCAM      0.0623      -0.2751     0.08 / 0.18
             *   SEX       0.1461      -0.1205     0.16 / 0.26
             *   THR       0.0789      -0.0676     0.09 / 0.15
             *
             * A negative harmful minimum means some harmful text of that
             * category reads, to this model, as less alarming than an
             * ordinary post. No threshold recovers those; the lexicon has
             * to, which is why the word lists carry the load here.
             */
            'THR' => ['review' => 0.09, 'block' => (float) env('TEXT_T_THREAT', 0.15)],
            'HAR' => ['review' => 0.15, 'block' => (float) env('TEXT_T_HARASSMENT', 0.25)],
            'SEX' => ['review' => 0.14, 'block' => (float) env('TEXT_T_SEXUAL', 0.26)],
            'SCAM' => ['review' => 0.08, 'block' => (float) env('TEXT_T_SCAM', 0.18)],
            'HATE' => ['review' => 0.02, 'block' => (float) env('TEXT_T_HATE', 0.12)],

            /*
             * GROUP 2 — clean separation, so each gets a threshold set
             * above its OWN safe ceiling with margin. These categories
             * previously had no threshold at all, which is why a broad
             * sweep found IMP 0/2, IP 0/1, POL 0/1 and SPAM 1/2: the
             * margins were computed, ranked correctly, and then discarded
             * because nothing told Laravel what to do with them.
             *
             * Re-measured under clause scoring, same discipline: review
             * sits just above the category's own safe ceiling.
             *
             *          safe max   harmful min   set to
             *   VIO     -0.0153      0.0366     0.02 / 0.10
             *   PRIV     0.0432      0.3653     0.06 / 0.20
             *   IMP      0.1153      0.1769     0.13 / 0.20
             *   CYBER    0.1049      0.0487     0.12 / 0.20
             *   DRUG     0.1836      0.0976     0.19 / 0.30
             *   CRIME    0.1339      0.0429     0.15 / 0.25
             *   ANIMAL   0.2684      0.4660     0.28 / 0.40
             *   SPAM    -0.0731      0.4124     0.05 (hold only)
             *   IP       0.1030      0.4550     0.12 (hold only)
             *   MISINFO  0.1059      0.2871     0.12 (hold only)
             *   POL      0.0863      0.4881     0.10 (hold only)
             *
             * PRIV, VIO and SPAM gained a lot of headroom from the short
             * benign clause exemplars: a fragment like "Program panoda
             * asılı" used to resemble no benign exemplar at all, so its
             * baseline collapsed and its margin inflated.
             *
             * The last four are hold-only on purpose. Political speech,
             * a copyright claim, a rumour and a spam complaint are
             * judgement calls: refusing them outright on a model's word
             * is too strong, and a moderator deciding is the right answer.
             */
            'VIO' => ['review' => 0.02, 'block' => (float) env('TEXT_T_VIOLENCE', 0.10)],
            'PRIV' => ['review' => 0.06, 'block' => 0.20],
            'IMP' => ['review' => 0.13, 'block' => 0.20],
            'CYBER' => ['review' => 0.12, 'block' => 0.20],
            'DRUG' => ['review' => 0.19, 'block' => 0.30],
            'CRIME' => ['review' => 0.07, 'block' => 0.15],
            'ANIMAL' => ['review' => 0.27, 'block' => 0.40],
            'SPAM' => ['review' => 0.05],
            'IP' => ['review' => 0.12],
            'MISINFO' => ['review' => 0.12],
            'POL' => ['review' => 0.10],

            /*
             * SELF takes `support`, never `block`.
             *
             * A student saying they are in crisis must be met with help,
             * not a refusal — published, shown counselling contacts, no
             * strike, with a review event so a moderator sees it. Letting
             * this layer treat SELF as a violation would turn a cry for
             * help into a rejected post, the worst outcome this system
             * can produce.
             *
             * Measured margins, which is where 0.15 comes from:
             *
             *   +0.4288  "artık dayanamıyorum, bu gece her şeyi bitireceğim"
             *   +0.2193  "bu son mesajım, herkes beni unutsun"
             *   +0.1113  "bu dönem çok yoruldum ama tatilde toparlanırım"
             *   -0.3695  "bu sınav çok zordu, moralim bozuldu"
             *   -0.6270  "stres ve intihar önleme semineri düzenliyor"
             *
             * 0.15 sits between real distress and ordinary end-of-term
             * exhaustion. At 0.05 the exhausted student got a counselling
             * offer, which is noise rather than care.
             *
             * The asymmetry still favours catching it: a false positive
             * costs an unnecessary offer of support, a false negative
             * costs someone in crisis being met with silence. So this
             * threshold sits well below the 0.15 used for refusals even
             * though the number reads the same — the *outcome* is not a
             * refusal.
             */
            /*
             * Lowered 0.15 -> 0.09 on 11 September 2026. Measured over 182
             * labelled safe texts the SELF ceiling is 0.0671 and the
             * lowest harmful SELF margin is 0.1234, so 0.09 sits inside a
             * real gap rather than being a guess — and of every threshold
             * in this file it is the one where a miss costs the most.
             */
            'SELF' => ['support' => (float) env('TEXT_T_SELF_SUPPORT', 0.09)],
        ],
    ],

    /*
     * Account-level consequences: strikes, suspensions, bans.
     *
     * Separate from content moderation, and separately switchable. With
     * this off, unsafe content is still detected and still refused —
     * nothing about what gets published changes. What stops is the
     * *account* paying for it: no strike is counted, no suspension is
     * applied, no ban is set.
     *
     * It exists because testing moderation means submitting violations
     * deliberately, and an escalation ladder cannot tell a test case from
     * a real one — it banned the project's own student test account after
     * three probes, which then looked like a login bug.
     *
     * Defaults to ON. A production deployment that does not set this gets
     * enforcement, because the failure mode of the opposite default is a
     * launched university app where nothing has any consequence.
     *
     * Suppressed consequences are still written to the audit log with
     * what *would* have happened, so a testing window leaves a record
     * rather than a gap. Turning it back on does not retroactively punish
     * anything skipped while it was off.
     */
    'enforcement' => [
        'enabled' => filter_var(env('MODERATION_ENFORCEMENT_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
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
