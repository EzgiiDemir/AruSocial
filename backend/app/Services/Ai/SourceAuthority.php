<?php

namespace App\Services\Ai;

/**
 * How much a source is allowed to decide a fact, and how fresh it is.
 *
 * The assistant reads from several systems at once, and they disagree. A
 * crawled web page says an event starts at 14:00 because it said so in
 * March; the events table says 15:00 because someone moved it yesterday.
 * Merging those, or letting the model pick, produces a confident wrong
 * answer — so the precedence is decided here, in one place, and applied
 * before the model sees anything.
 *
 * The ordering is by how directly a source knows the fact:
 *
 *   PERSONAL (100)   the asker's own row, read live. Nothing else can know
 *                    their appointment; nothing else may claim to.
 *   OPERATIONAL (80) our own structured tables — events, places, shuttle,
 *                    services, staff, clubs. Written by the people who run
 *                    the thing, changed the moment it changes.
 *   OFFICIAL_DOC (60) published regulations and policies. Authoritative
 *                    and slow-moving; see the PDF gap in the knowledge map.
 *   WEB (40)         crawled arucad.edu.tr pages. Official, but a snapshot
 *                    of a page that may have been edited since.
 *   CONVERSATION (20) what was said earlier in this thread. Context for
 *                    resolving "it" and "there" — NEVER a fact.
 *   MODEL (0)        the model's pretrained knowledge. Not an authority on
 *                    anything ARUCAD-specific, at all, ever.
 *
 * The numbers exist so the ordering is sortable and testable, not because
 * the gaps mean anything.
 */
final class SourceAuthority
{
    /** The asking student's own live data. */
    public const PERSONAL = 100;

    /** Our own structured operational tables. */
    public const OPERATIONAL = 80;

    /** Published official documents: regulations, policies, handbooks. */
    public const OFFICIAL_DOC = 60;

    /** Crawled official ARUCAD web pages. */
    public const WEB = 40;

    /**
     * A page read from the open web at question time.
     *
     * Below our own crawled pages on purpose. We chose what to crawl; a
     * search engine chose this, and it may be a third party writing about
     * ARUCAD rather than ARUCAD writing about itself. Useful, citable, and
     * never allowed to outrank the university's own statement.
     */
    public const EXTERNAL_WEB = 30;

    /** Earlier turns of this conversation. Context, not evidence. */
    public const CONVERSATION = 20;

    /** What the model learned in training. Never authoritative here. */
    public const MODEL = 0;

    /**
     * Who may see it. Drives both what enters the prompt and what may be
     * shown as a citation: a student's own appointment is a legitimate
     * input to their answer and an illegitimate "source card".
     */
    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_INTERNAL = 'internal';

    public const VISIBILITY_USER_PRIVATE = 'user_private';

    /**
     * How fast a source goes out of date. Used to decide whether an answer
     * may be presented as current, and to label it when it may not.
     */
    public const FRESHNESS_LIVE = 'live';        // read at question time

    public const FRESHNESS_DAILY = 'daily';      // crawled on a schedule

    public const FRESHNESS_STATIC = 'static';    // rarely changes

    /** @var array<int, string> */
    private const LABELS = [
        self::PERSONAL => 'Öğrencinin kendi verisi',
        self::OPERATIONAL => 'ARUCAD veritabanı',
        self::OFFICIAL_DOC => 'Resmî ARUCAD belgesi',
        self::WEB => 'ARUCAD web sitesi',
        self::EXTERNAL_WEB => 'Dış web kaynağı',
        self::CONVERSATION => 'Önceki mesajlar',
        self::MODEL => 'Model bilgisi',
    ];

    public static function label(int $authority): string
    {
        return self::LABELS[$authority] ?? 'Bilinmeyen kaynak';
    }

    /**
     * May a source at this level be shown to the student as a citation?
     *
     * Their own data may inform the answer and must never be rendered as a
     * source card: "according to <your appointment>" is not a citation, it
     * is a disclosure, and on a shared screen it is a disclosure to
     * whoever is looking.
     */
    public static function isCitable(string $visibility): bool
    {
        return $visibility !== self::VISIBILITY_USER_PRIVATE;
    }

    /**
     * Sort sources strongest-first, newest-first within a level.
     *
     * Stable on authority so an equally-authoritative pair keeps the order
     * retrieval chose, which is by relevance.
     *
     * @param  list<array<string, mixed>>  $sources
     * @return list<array<string, mixed>>
     */
    public static function rank(array $sources): array
    {
        usort($sources, function (array $a, array $b): int {
            $byAuthority = ($b['authority'] ?? self::WEB) <=> ($a['authority'] ?? self::WEB);
            if ($byAuthority !== 0) {
                return $byAuthority;
            }

            // A stale snapshot loses to a current one of equal standing.
            return (int) ($a['stale'] ?? false) <=> (int) ($b['stale'] ?? false);
        });

        return array_values($sources);
    }
}
