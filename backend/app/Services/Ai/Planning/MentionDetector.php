<?php

namespace App\Services\Ai\Planning;

use App\Services\Ai\EntityResolver;
use App\Support\TextFold;

/**
 * Finds what the user's message refers to, and offers candidate entities —
 * without choosing the final one. Final, task-specific resolution happens in
 * TaskExecutor ("Öğrenci İşleri" is a service; for a route it becomes the
 * building the service is in).
 *
 * The original message is never rewritten. Spans are character offsets into
 * the folded message (TextFold keeps the length of Turkish letters).
 */
final class MentionDetector
{
    /** Words that point at something said before, in tr/en/ru (folded). */
    private const REFERENCES = [
        'oraya', 'orasi', 'oradan', 'orada', 'orasinin', 'buraya', 'burasi', 'oraya kadar',
        'bu kulup', 'bu kulubun', 'bu bina', 'bu binanin',
        'there', 'that place', 'over there', 'this club', 'that club', 'туда', 'там', 'оттуда', 'этот клуб', 'этого клуба',
    ];

    /**
     * A bare type noun in a case form — "kulübün instagramı", "kulübe nasıl
     * giderim", "the club's room", "комната клуба" — refers to the latest
     * entity OF THAT TYPE (in this message, else an earlier turn). The bare
     * nominative ("kulüp", "клуб") is left out: "hangi kulüp" asks, it does
     * not point. Folded.
     *
     * @var array<string, list<string>>
     */
    private const TYPED_REFERENCES = [
        'club' => ['kulubun', 'kulubunun', 'kulube', 'kulubune', 'kulupte', 'kulupten', 'kulubu', 'kulup odasi', 'the club', "the club's", 'клуба', 'клубе', 'клубу'],
        'sport' => ['takimin', 'takimina', 'takima', 'takimda', 'takimi', 'the team', "the team's", 'команды', 'команде', 'команду'],
    ];

    public function __construct(private readonly EntityResolver $entities) {}

    /** @return list<Mention> */
    public function references(string $query): array
    {
        $folded = TextFold::fold($query);
        $out = [];
        foreach (self::REFERENCES as $word) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/u', $folded, $m, PREG_OFFSET_CAPTURE) === 1) {
                $start = mb_strlen(substr($folded, 0, $m[0][1]));
                $out[] = new Mention($word, 'conversation_reference', $start, $start + mb_strlen($word));
            }
        }
        foreach (self::TYPED_REFERENCES as $type => $words) {
            foreach ($words as $word) {
                if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($word, '/').'(?![\p{L}\p{N}])/u', $folded, $m, PREG_OFFSET_CAPTURE) !== 1) {
                    continue;
                }
                $start = mb_strlen(substr($folded, 0, $m[0][1]));
                $end = $start + mb_strlen($word);
                // "bu kulübün" is already one reference.
                if (! collect($out)->contains(fn (Mention $o) => $start < $o->end && $end > $o->start)) {
                    $out[] = new Mention($word, 'conversation_reference:'.$type, $start, $end);
                }
            }
        }

        return $out;
    }

    /**
     * Entity mentions with their preliminary candidates.
     *
     * One mention per matched phrase. Candidates sharing a phrase across
     * types (an office and the building it is in) are kept together and the
     * mention is RESOLVED to the best; two candidates of the SAME type that
     * EntityResolver marked ambiguous make it AMBIGUOUS, and both are kept.
     *
     * @return list<EntityResolution>
     */
    public function resolve(string $query): array
    {
        $folded = TextFold::fold($query);
        $groups = [];
        foreach ($this->entities->resolve($query) as $entity) {
            $groups[(string) $entity['matched']][] = $entity;
        }

        $out = [];
        foreach ($groups as $phrase => $entities) {
            $offset = mb_strpos($folded, (string) $phrase);
            $mention = new Mention((string) $phrase, 'entity', $offset === false ? -1 : $offset,
                $offset === false ? -1 : $offset + mb_strlen((string) $phrase));
            usort($entities, fn ($a, $b) => $b['score'] <=> $a['score']);
            $candidates = array_map(fn (array $e) => new PreliminaryEntityCandidate(
                (string) $e['type'], (string) $e['id'], (string) $e['name'], (float) $e['score'],
                match ($e['method']) {
                    'name' => 'exact_name', 'alias' => 'alias', default => 'fuzzy'
                },
                match (true) {
                    $e['method'] === 'fuzzy' => 'low', ($e['ambiguous'] ?? false) => 'medium', default => 'high'
                },
            ), $entities);

            $ambiguous = collect($entities)->contains(fn ($e) => $e['ambiguous'] ?? false);
            $sameType = array_values(array_filter($candidates, fn ($c) => $c->entityType === $candidates[0]->entityType));
            $margin = isset($sameType[1]) ? round($sameType[0]->resolverScore - $sameType[1]->resolverScore, 2) : null;

            $out[] = new EntityResolution(
                $mention,
                $ambiguous ? ResolutionStatus::AMBIGUOUS : ResolutionStatus::RESOLVED,
                $candidates,
                $margin,
                $ambiguous
                    ? 'several '.$candidates[0]->entityType.' rows share this phrase with no decisive margin'
                    : ($candidates[0]->matchType === 'fuzzy' ? 'single typo-tolerant match' : 'single '.$candidates[0]->matchType.' match'),
            );
        }
        usort($out, fn ($a, $b) => $a->mention->start <=> $b->mention->start);

        return $out;
    }

    /**
     * What a conversation reference ("oraya") points at: an entity named
     * earlier in the SAME message, else the latest earlier user turn that
     * resolves. Structured — the message itself is left untouched.
     *
     * @param  list<EntityResolution>  $resolutions  of the current message
     * @param  list<array{role: string, content: string}>  $history
     *                                                               The whole resolution is carried, not just its best candidate: a route
     *                                                               to "öğrenci işleri" needs the office's building, which the task-level
     *                                                               resolution picks from the same candidates.
     * @return array{resolution: EntityResolution, source: string}|null
     */
    public function referent(Mention $reference, array $resolutions, array $history): ?array
    {
        // A typed reference ("kulübün") accepts only an entity of its type.
        $type = str_starts_with($reference->typeHint, 'conversation_reference:') ? substr($reference->typeHint, 23) : null;
        $fits = fn (EntityResolution $r) => $type === null || collect($r->candidates)->contains(fn ($c) => $c->entityType === $type);
        foreach (array_reverse($resolutions) as $resolution) {
            if ($resolution->mention->start >= 0 && $resolution->mention->start < $reference->start
                && $resolution->status !== ResolutionStatus::UNRESOLVED && $fits($resolution)) {
                return ['resolution' => $resolution, 'source' => 'same_message'];
            }
        }
        foreach (array_reverse($history) as $turn) {
            if (($turn['role'] ?? 'user') !== 'user') {
                continue;
            }
            foreach ($this->resolve((string) $turn['content']) as $resolution) {
                if ($fits($resolution)) {
                    return ['resolution' => $resolution, 'source' => 'previous_turn'];
                }
            }
        }

        return null;
    }
}
