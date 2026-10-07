<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Site knowledge crawler
    |--------------------------------------------------------------------------
    |
    | A fully self-hosted knowledge source for Ask ARUVERSE. It fetches the
    | public ARUCAD web pages listed below, extracts their readable text, and
    | stores it locally so the assistant can answer from current site content
    | without any external search API or third-party crawler service.
    |
    | Only PUBLIC pages. Anything requiring a login (sis.arucad.edu.tr, the
    | wp-admin panels) is deliberately excluded — this bot reads the same
    | pages a visitor sees, never student or account data.
    |
    | The crawler runs on a schedule (routes/console.php) so the answers stay
    | up to date on their own.
    |
    */

    'enabled' => filter_var(env('KNOWLEDGE_CRAWLER_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    // Whether to crawl sources marked `local` (reachable only inside the ARUCAD
    // network). Default true — when the crawler runs on-network it can reach
    // them. Set KNOWLEDGE_CRAWL_LOCAL=false on a crawler that runs OUTSIDE the
    // network so it skips local-only sites cleanly instead of failing on them.
    'crawl_local' => filter_var(env('KNOWLEDGE_CRAWL_LOCAL', true), FILTER_VALIDATE_BOOLEAN),

    // Windows/local PHP frequently lacks a CA bundle (cURL 60), the same
    // fallback the routing/Groq/directory integrations already use.
    'verify_ssl' => filter_var(
        env('KNOWLEDGE_VERIFY_SSL', env('APP_ENV') === 'local' ? 'false' : 'true'),
        FILTER_VALIDATE_BOOLEAN
    ),

    // Politeness: never hammer the university's servers.
    'request_timeout' => (int) env('KNOWLEDGE_TIMEOUT_SECONDS', 20),
    'delay_ms' => (int) env('KNOWLEDGE_DELAY_MS', 500),

    // A page older than this is re-fetched on the next run; a fresher one is
    // skipped so a crawl is cheap and mostly a no-op between content changes.
    'refresh_after_hours' => (int) env('KNOWLEDGE_REFRESH_HOURS', 12),

    // A small set of high-value pages may be refreshed when a matching
    // question is asked and their stored copy is missing/stale. The scheduled
    // crawler remains the main updater; this closes the gap between a newly
    // published calendar and the next scheduled run. Disabled automatically
    // in tests unless a test opts in, so test runs never make live HTTP calls.
    'on_demand_enabled' => filter_var(
        env('KNOWLEDGE_ON_DEMAND_ENABLED', env('APP_ENV') === 'testing' ? 'false' : 'true'),
        FILTER_VALIDATE_BOOLEAN
    ),
    /*
     * On-demand refresh driven by the curated page keywords.
     *
     * The only path where a student's question causes an outbound fetch, so
     * it is bounded on three axes: how many pages one question may refresh,
     * how many pages a single keyword may point at before it counts as too
     * vague to identify anything, and how long a refresh is cached.
     */
    /*
     * How long a term's document frequency is trusted.
     *
     * Only used to weight a term by rarity, so a slightly stale count costs
     * a marginally worse ranking and never a wrong answer. The key carries
     * the corpus size, so a crawl that adds pages invalidates it anyway.
     */
    'document_frequency_cache_minutes' => (int) env('KNOWLEDGE_DF_CACHE_MINUTES', 120),

    'on_demand_max_pages' => (int) env('KNOWLEDGE_ON_DEMAND_MAX_PAGES', 2),
    'on_demand_max_spread' => (int) env('KNOWLEDGE_ON_DEMAND_MAX_SPREAD', 6),
    /*
     * An hour, not ten minutes.
     *
     * The cache is per URL, so this is what bounds the load this feature can
     * put on the university's own web server. Across 852 curated pages, ten
     * minutes allows roughly 1.4 requests a second in the worst case and an
     * hour allows a quarter of that. Nothing here is worth being the reason
     * arucad.edu.tr slows down, and a page that changed within the hour is
     * still served from a snapshot minutes old rather than weeks.
     */
    'on_demand_cache_minutes' => (int) env('KNOWLEDGE_ON_DEMAND_CACHE_MINUTES', 60),

    'on_demand_sources' => [
        [
            'keywords' => [
                'akademik takvim', 'dersler ne zaman', 'ders ne zaman',
                'ders başlangıcı', 'dönem ne zaman', 'semester start',
                'term start', 'academic calendar', 'начало занятий',
                'академический календарь',
            ],
            'url' => 'https://arucad.edu.tr/lisans-akademik-takvim/',
        ],
    ],

    // How much of one page's text to keep, bounded so a single huge page
    // cannot dominate storage. It does not bound the AI context — the model
    // gets a snippet window, not the page. Was 8,000, which cut 82 HTML
    // pages: a programme page is ~32,500 characters and its "Eğitim Dili"
    // section sits at character ~27,200, so it was never searchable.
    'max_chars_per_page' => (int) env('KNOWLEDGE_MAX_CHARS', 40000),

    // PDFs are allowed through the same crawler and safety boundary as HTML.
    // They need a larger cap because regulations are multi-page documents;
    // chunk limits still bound how much reaches retrieval and the model.
    'max_chars_per_pdf' => (int) env('KNOWLEDGE_MAX_PDF_CHARS', 250000),
    'max_pdf_bytes' => (int) env('KNOWLEDGE_MAX_PDF_BYTES', 15000000),

    /*
     * Hard transport ceiling for any single fetch.
     *
     * Enforced while reading the stream, not after. The PDF limit above is a
     * policy about what is worth indexing; this is what stops one oversized
     * file taking the whole run down with it — measured, a 32 MB body
     * exhausted a 128 MB process part-way through a crawl and lost every
     * page after it. Never set it below max_pdf_bytes or legitimate
     * documents are refused at the transport before policy sees them.
     */
    'max_bytes' => (int) env('KNOWLEDGE_MAX_BYTES', 16000000),
    'pdf_parser_memory_limit' => env('KNOWLEDGE_PDF_PARSER_MEMORY', '192M'),
    'pdf_parser_timeout' => (int) env('KNOWLEDGE_PDF_PARSER_TIMEOUT', 30),

    // Seed pages. The crawler starts here; with discovery on (below) it also
    // follows in-domain links it finds, so new pages are picked up without
    // editing this list.
    'seed_urls' => [
        // ARUCAD main website
        'https://arucad.edu.tr/',
        'https://arucad.edu.tr/en/',
        'https://arucad.edu.tr/fakulteler/',
        'https://arucad.edu.tr/en/faculties/',
        'https://arucad.edu.tr/iletisim/',
        'https://arucad.edu.tr/kutuphane/',
        'https://arucad.edu.tr/en/library/',
        'https://arucad.edu.tr/category/haberler/',
        'https://arucad.edu.tr/uluslararasi-olanaklar/',
        'https://arucad.edu.tr/arucad/is-olanaklari/',
        'https://arucad.edu.tr/arucad/kariyer-ve-mezun-ofisi/',
        'https://arucad.edu.tr/lisans-akademik-takvim/',
        'https://arucad.edu.tr/yonetmelikler/',
        'https://arucad.edu.tr/denklik-ve-uyelikler/',
        'https://arucad.edu.tr/atolyeler/',
        'https://arucad.edu.tr/studyolar/',
        'https://arucad.edu.tr/laboratuvarlar/',
        'https://arucad.edu.tr/sahneler/',
        'https://arucad.edu.tr/kampus-yasami/ogrenci-kulupleri/',
        'https://arucad.edu.tr/ulasim/',

        // Faculties / programs
        'https://arucad.edu.tr/rt-faculty/sanat-fakultesi/',
        'https://arucad.edu.tr/rt-program-category/sanat-fakultesi/',
        'https://arucad.edu.tr/rt-program-category/tasarim-fakultesi/',
        'https://arucad.edu.tr/rt-program/iletisim-calismalari/',

        // Candidate student website
        'https://aday.arucad.edu.tr/',
        'https://aday.arucad.edu.tr/lisans-programlari/',
        'https://aday.arucad.edu.tr/neden-arucad/',
        'https://aday.arucad.edu.tr/iletisim/',
        'https://aday.arucad.edu.tr/dikey-gecis-sinavi/',
        'https://aday.arucad.edu.tr/yatay-gecis/',
        'https://aday.arucad.edu.tr/sikca-sorulan-sorular/',
        'https://aday.arucad.edu.tr/ucret-hesaplama-modulu/',
        'https://aday.arucad.edu.tr/burs-ve-indirimler/',
        'https://aday.arucad.edu.tr/rt-program/plastik-sanatlar/',
        'https://aday.arucad.edu.tr/rt-program/seramik/',
        'https://aday.arucad.edu.tr/rt-program/fotograf/',
        'https://aday.arucad.edu.tr/rt-program/arkeoloji/',
        'https://aday.arucad.edu.tr/rt-program/film-tasarimi-ve-yonetimi/',
        'https://aday.arucad.edu.tr/rt-program/tekstil-ve-moda-tasarimi/',
        'https://aday.arucad.edu.tr/rt-program/mimarlik/',
        'https://aday.arucad.edu.tr/rt-program/ic-mimarlik-ve-cevre-tasarimi/',
        'https://aday.arucad.edu.tr/rt-program/endustriyel-tasarim/',
        'https://aday.arucad.edu.tr/rt-program/kentsel-tasarim-ve-peyzaj-mimarligi/',
        'https://aday.arucad.edu.tr/rt-program/dijital-oyun-tasarimi/',
        'https://aday.arucad.edu.tr/rt-program/yeni-medya-ve-iletisim/',
        'https://aday.arucad.edu.tr/rt-program/gorsel-iletisim-tasarimi/',
        'https://aday.arucad.edu.tr/rt-program/oyunculuk/',
        'https://aday.arucad.edu.tr/rt-program/modern-dans/',
        'https://aday.arucad.edu.tr/rt-program/ses-sanatlari-tasarimi/',
        'https://aday.arucad.edu.tr/rt-program/ingilizce-hazirlik-okulu/',

        // Application platform — PUBLIC pages only (never wp-admin)
        'https://apply.arucad.edu.tr/',
        'https://apply.arucad.edu.tr/?lang=en',
        'https://apply.arucad.edu.tr/arucad-2026-2027-akademik-yili-yks-kayit-formu/',
        'https://apply.arucad.edu.tr/arucad-2026-2027-akademik-yili-dgs-kayit-formu/',
        'https://apply.arucad.edu.tr/arucad-2026-2027-ogretim-yili-ozel-yetenek-kayit-formu/',
        'https://apply.arucad.edu.tr/2026-2027-ozel-yetenek-on-line-cizim-sinavi-basvuru-formu/',
        'https://apply.arucad.edu.tr/2026-2027-muzik-ve-sahne-sanatlari-fakultesi-ozel-yetenek-sinavi-basvuru-formu/',
        'https://apply.arucad.edu.tr/2026-trnc-aptitude-exam-and-interview-application-form/',
        'https://apply.arucad.edu.tr/7-liseler-arasi-tasarim-yarismasi-basvuru-formu/',

        // Other public subdomains
        'https://academics.arucad.edu.tr/',
        'https://prospective.arucad.edu.tr/',
        'https://prospective.arucad.edu.tr/contact-us/',
        'https://kibrisaday.arucad.edu.tr/',
        'https://kibrisaday.arucad.edu.tr/iletisim/',

        /*
         * Announcements and staff pages.
         *
         * Added from a supplied inventory of the live site after measuring
         * what the index actually held: of 594 documents, `rt-notice` and
         * `/teams/` were at ZERO. Everything else in that inventory already
         * had coverage through the seeds, the sitemap and link-following, so
         * only these two are listed here — a few hundred more URLs the
         * crawler already reaches would be noise to maintain.
         *
         * They matter more than their count suggests: rt-notice is where the
         * exam schedules live, which is one of the few things a student needs
         * an exact answer to and cannot guess.
         */
        'https://arucad.edu.tr/rt-notice/2023-2024-bahar-donemi-final-sinav-takvimi/',
        'https://arucad.edu.tr/rt-notice/2024-2025-bahar-donemi-ara-sinav-takvimi/',
        'https://arucad.edu.tr/rt-notice/2024-2025-final-donemi-final-sinav-takvimi/',
        'https://arucad.edu.tr/rt-notice/2024-2025-guz-donemi-ara-sinav-takvimi/',
        'https://arucad.edu.tr/rt-notice/2024-2025-guz-donemi-final-sinav-takvimi/',
        'https://arucad.edu.tr/rt-notice/2025-2026-guz-donemi-final-sinav-takvimi/',
        'https://arucad.edu.tr/rt-notice/acik-cagri/',
        'https://arucad.edu.tr/rt-notice/arucadda-dumansiz-kampus-icin-ortak-adim/',
        'https://arucad.edu.tr/rt-notice/universitemizde-yapilacak-docentlik-sozlu-sinav-duyurusu/',
        'https://arucad.edu.tr/en/rt-notice/2023-2024-academic-year-spring-semester-final-exam-schedule/',
        'https://arucad.edu.tr/en/rt-notice/2024-2025-academic-year-fall-semester-final-exam-schedule/',
        'https://arucad.edu.tr/en/rt-notice/2024-2025-academic-year-spring-semester-final-exam-schedule/',
        'https://arucad.edu.tr/en/rt-notice/2024-2025-fall-midterm-exam-schedule/',
        'https://arucad.edu.tr/en/rt-notice/2024-2025-fall-preparation-school-placement-exam-dates/',
        'https://arucad.edu.tr/en/rt-notice/2024-2025-spring-midterm-exam-schedule/',
        'https://arucad.edu.tr/en/rt-notice/2025-2026-fall-final-exam-schedule/',
        'https://arucad.edu.tr/en/rt-notice/a-collective-step-toward-a-smoke-free-campus-at-arucad-our-campus-our-choice/',
        'https://arucad.edu.tr/en/rt-notice/open-call/',
        'https://arucad.edu.tr/teams/prof-dr-asim-vehbi/',
        'https://arucad.edu.tr/teams/prof-dr-burcu-toker/',
        'https://arucad.edu.tr/teams/prof-dr-nezahat-dogan/',
        'https://arucad.edu.tr/teams/sadi-sinan-arkin/',
        'https://arucad.edu.tr/teams/ulku-sim/',
        'https://arucad.edu.tr/teams/yrd-doc-dr-ibrahim-dalkilic/',
        'https://arucad.edu.tr/teams/yrd-doc-dr-merve-senem-arkan/',
        'https://arucad.edu.tr/teams/ogr-gor-inanc-ucaroz/',
        'https://arucad.edu.tr/en/teams/prof-dr-asim-vehbi-2/',
        'https://arucad.edu.tr/en/teams/prof-dr-burcu-toker-2/',
        'https://arucad.edu.tr/en/teams/prof-dr-nezahat-dogan-2/',
        'https://arucad.edu.tr/en/teams/asst-prof-dr-sinan-arkin/',
        'https://arucad.edu.tr/en/teams/asst-prof-dr-ibrahim-dalkilic-2/',
        'https://arucad.edu.tr/en/teams/asst-prof-dr-merve-senem-arkan/',
        'https://arucad.edu.tr/en/teams/sen-ins-inanc-ucaroz/',
    ],

    // A crawled/discovered URL is kept only if its host is one of these.
    'allowed_domains' => [
        'arucad.edu.tr',
        'aday.arucad.edu.tr',
        'apply.arucad.edu.tr',
        'prospective.arucad.edu.tr',
        'kibrisaday.arucad.edu.tr',
        'academics.arucad.edu.tr',
    ],

    // Never fetch anything whose path starts with one of these — login and
    // account surfaces are out of scope for a public knowledge bot. This is
    // also why sis.arucad.edu.tr is not an allowed domain at all.
    'excluded_path_prefixes' => [
        '/wp-admin', '/wp-login.php', '/login', '/logout',
        '/admin', '/account', '/profile', '/my-account', '/cart', '/checkout',
    ],

    // Auto-discovery: follow same-domain links found on fetched pages so the
    // crawler notices new pages on its own instead of only reading the seed
    // list. Bounded by max_pages so one run cannot walk the whole site.
    // Defence in depth against SSRF: reject an allow-listed host that resolves
    // to a private/loopback IP (DNS rebinding).
    //
    // OFF by default, on purpose. The PRIMARY SSRF protection is the hard-coded
    // `allowed_domains` list below — the crawler can only ever fetch those six
    // ARUCAD hosts. When the server runs INSIDE ARUCAD's network (split-horizon
    // DNS), those same public domains legitimately resolve to internal
    // addresses, so a private-IP block would refuse every real page. Enable
    // this only where the crawler runs on a network that sees these domains
    // via their public IPs.
    'verify_public_ip' => filter_var(env('KNOWLEDGE_VERIFY_PUBLIC_IP', false), FILTER_VALIDATE_BOOLEAN),

    // Follow each seed host's /sitemap.xml to discover pages the link graph
    // might miss. Bounded by max_pages like everything else.
    'use_sitemaps' => filter_var(env('KNOWLEDGE_USE_SITEMAPS', true), FILTER_VALIDATE_BOOLEAN),

    // A crawl-time cap on redirects, so a malicious/looping redirect chain
    // cannot walk the crawler somewhere unexpected.
    'max_redirects' => (int) env('KNOWLEDGE_MAX_REDIRECTS', 3),

    'discover_links' => filter_var(env('KNOWLEDGE_DISCOVER', true), FILTER_VALIDATE_BOOLEAN),
    /*
     * How many pages one crawl run may read.
     *
     * Was 120, which is fewer pages than arucad.edu.tr publishes: a supplied
     * inventory of the site listed roughly 400 URLs for that host alone, and
     * only 239 of them were ever indexed. Everything past the cap simply never
     * got read, so a question about one of those pages could only be answered
     * from whatever else happened to mention the topic — which is exactly the
     * "it does not really know ARUCAD" symptom.
     *
     * Raised to cover the real site. The cost is not the crawl, which runs on
     * a schedule; it is retrieval, which scans every indexed document per
     * question (~173 ms at 380 documents, and linear from there). Above about
     * a thousand pages that scan needs replacing with the vector-column lookup
     * described in docs/AI_AND_SCALE_PLAN.md §4.3 rather than raising this
     * again.
     */
    'max_pages' => (int) env('KNOWLEDGE_MAX_PAGES', 600),

    /*
     * Semantic retrieval.
     *
     * Served by the moderation classifier, which already holds a
     * multilingual sentence model in memory — so this adds a loopback
     * call and roughly 30 ms per question, not a second model, and no
     * student question leaves ARUCAD. With the classifier down or this
     * disabled, retrieval falls back to keyword matching: worse answers,
     * never an outage.
     */
    /*
     * Keyword scoring weights.
     *
     * `term_cap` is the most any single term can contribute, however
     * often it appears. Unbounded counts were measured returning the
     * wrong page — a long page repeating a common word outscored the
     * page that was actually about the question — and they also swamp
     * the semantic score they are meant to be weighed against.
     */
    'scoring' => [
        'term_cap' => (float) env('KNOWLEDGE_TERM_CAP', 3),
        'title_bonus' => (float) env('KNOWLEDGE_TITLE_BONUS', 6),
        'path_bonus' => (float) env('KNOWLEDGE_PATH_BONUS', 3),

        /*
         * Operator routing keys (admin panel -> Crawl sources -> Keys).
         *
         * Worth about as much as a path hit and less than a title hit: enough
         * to break a tie towards the source an operator says owns the topic,
         * not enough to drag an unrelated page from that domain above a page
         * that actually answers the question.
         */
        'source_key_bonus' => (float) env('KNOWLEDGE_SOURCE_KEY_BONUS', 4),

        /*
         * The most a long article slug can lose to the aboutness penalty.
         *
         * Capped at less than a title hit on purpose. A news post whose title
         * matches the question should still win; what the penalty removes is
         * the case where several articles that merely mention the subject
         * crowd out the one page named for it.
         */
        'article_penalty_cap' => (float) env('KNOWLEDGE_ARTICLE_PENALTY_CAP', 4),

        /*
         * Weight each query term by how rare it is in the corpus.
         *
         * On by default; the switch exists because this reweights every
         * result at once, so an operator seeing a ranking go wrong after a
         * large crawl can turn it off and compare rather than guess.
         */
        /*
         * Authored per-page keywords (knowledge:keywords:import).
         *
         * Larger than a title hit, because a keyword is a human saying what
         * the page is FOR, which is better evidence than any count of what
         * it contains. The cap stops a page with forty keywords from
         * winning on breadth alone.
         */
        /*
         * Demotion per academic year out of date, and its ceiling.
         *
         * Large on purpose: last year's exam calendar is not a slightly
         * worse answer than this year's, it is a wrong one a student would
         * act on. Not a filter, because a question that names an old year
         * should still find it — that case skips this entirely.
         */
        'stale_year_penalty' => (float) env('KNOWLEDGE_STALE_YEAR_PENALTY', 6),
        'stale_year_cap' => (float) env('KNOWLEDGE_STALE_YEAR_CAP', 12),

        'page_keyword_bonus' => (float) env('KNOWLEDGE_PAGE_KEYWORD_BONUS', 5),
        'page_keyword_cap' => (float) env('KNOWLEDGE_PAGE_KEYWORD_CAP', 12),
        // Previously literals inside KnowledgeBase::relevant(); moved here
        // unchanged so the Search Playground can show them and a test can
        // tune them without a code change.
        // A page in the question's own language.
        'language_bonus' => (float) env('KNOWLEDGE_LANGUAGE_BONUS', 3),
        // A page fetched within the last week edges out an equal older one.
        'freshness_bonus' => (float) env('KNOWLEDGE_FRESHNESS_BONUS', 2),
        // Multiplier for a page the crawler marked stale (stopped resolving).
        'stale_multiplier' => (float) env('KNOWLEDGE_STALE_MULTIPLIER', 0.2),
        // Official documents: only on a score already this relevant...
        'official_min_score' => (float) env('KNOWLEDGE_OFFICIAL_MIN_SCORE', 10),
        // ...worth this much when the student asks about a regulation...
        'official_regulation_bonus' => (float) env('KNOWLEDGE_OFFICIAL_REGULATION_BONUS', 8),
        // ...and only enough to settle a near-tie otherwise.
        'official_tiebreak_bonus' => (float) env('KNOWLEDGE_OFFICIAL_TIEBREAK_BONUS', 1),
        // Candidate generation: a body match only nominates a page when the
        // term's rarity weight is at least this (0.75 = in under ~a sixth of
        // the corpus). Title, URL and semantic matches nominate regardless.
        'candidate_min_term_weight' => (float) env('KNOWLEDGE_CANDIDATE_MIN_TERM_WEIGHT', 0.75),
        // A page on a matched query concept's preferred path (admin → AICAD →
        // Query concepts). Title-hit sized: settles near-ties only.
        'concept_path_bonus' => (float) env('KNOWLEDGE_CONCEPT_PATH_BONUS', 6),

        'rarity_weighting' => filter_var(
            env('KNOWLEDGE_RARITY_WEIGHTING', true), FILTER_VALIDATE_BOOLEAN),
    ],

    /*
     * Citation eligibility. A page with no keyword evidence is in the
     * results on vector similarity alone; it reaches the prompt (and the
     * student's citations) only above this similarity, and never while
     * structured data answers. 0.60 is the low end of this model's measured
     * same-meaning range; see KnowledgeBase::eligible().
     */
    'citations' => [
        'semantic_only_min_similarity' => (float) env('KNOWLEDGE_SEMANTIC_ONLY_MIN_SIMILARITY', 0.6),
    ],

    'embeddings' => [
        'enabled' => filter_var(env('KNOWLEDGE_EMBEDDINGS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

        // Same service as text moderation by default; they are the same
        // process and the same weights.
        'base_url' => rtrim((string) env('KNOWLEDGE_EMBEDDINGS_URL',
            env('TEXT_MODERATION_URL', 'http://127.0.0.1:8801')), '/'),
        'timeout' => (float) env('KNOWLEDGE_EMBEDDINGS_TIMEOUT', 10),

        // Texts per HTTP call during indexing.
        'batch' => (int) env('KNOWLEDGE_EMBEDDINGS_BATCH', 32),

        /*
         * Passage size. 700 characters is roughly 150-200 tokens, which
         * fits inside the model's 256-token window with room for the
         * title prefix — a longer chunk would be silently truncated and
         * its tail would never be searchable.
         */
        'chunk_chars' => (int) env('KNOWLEDGE_CHUNK_CHARS', 700),
        'max_chunks_per_document' => (int) env('KNOWLEDGE_MAX_CHUNKS', 12),

        /*
         * Similarity floor.
         *
         * MEASURED on this model: unrelated campus questions score about
         * 0.21, same-meaning pairs 0.60-0.70, and the same question in
         * another language 0.51-0.64. 0.25 sits just above the noise
         * floor, so it drops the clearly-unrelated and lets ranking
         * decide the rest. It is deliberately NOT a relevance threshold:
         * a loosely related pair reached 0.39 while a true cross-lingual
         * match scored 0.37, so any cutoff high enough to "decide"
         * relevance would throw away real answers.
         */
        'min_similarity' => (float) env('KNOWLEDGE_MIN_SIMILARITY', 0.25),

        /*
         * How much a perfect semantic match is worth in keyword points.
         *
         * 20 puts a 0.6 similarity at about 12 points — comparable to a
         * title hit plus a couple of body matches, so meaning can lift a
         * page that shares no wording without burying one that matches
         * literally.
         */
        'weight' => (int) env('KNOWLEDGE_SEMANTIC_WEIGHT', 20),
        /*
         * How many pages the semantic signal may nominate per question.
         *
         * The floor above only removes noise; on this corpus ~2,000 of
         * 2,439 pages clear it for an ordinary question. Only the closest
         * pages (by their best passage) become candidates and earn semantic
         * points; keyword candidates are unaffected. Chosen with
         * `ask:benchmark` — see docs/AICAD_KNOWLEDGE.md.
         */
        'max_candidates' => (int) env('KNOWLEDGE_SEMANTIC_MAX_CANDIDATES', 40),

        // A lecture hall asking the same question in the same minute
        // should cost one inference, not one each.
        'query_cache_minutes' => (int) env('KNOWLEDGE_QUERY_CACHE_MINUTES', 30),

        'model_label' => env('KNOWLEDGE_EMBEDDINGS_MODEL', 'classifier-minilm'),
    ],

];
