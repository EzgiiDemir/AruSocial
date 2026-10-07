<?php

namespace App\Services\Ai\Evidence;

/**
 * Reconciles the eligible evidence for one requirement, deterministically.
 *
 * Only comparable values about the same subject can conflict: hours of two
 * different venues are not a disagreement, and document passages are
 * support, never compared as facts. Rules, in order:
 *
 *  1. explicit_supersedes — a current item that says it replaces this fact
 *  2. current_exception_over_generic — a currently valid exception (a
 *     temporary change) over the standing value
 *  3. authority — the policy's strongest class, when it agrees with itself
 *
 * When none separates the values, the result is UNRESOLVED_CONFLICT and
 * every side is kept; choosing would be inventing.
 */
final class ConflictResolver
{
    /** @param list<AssessedEvidence> $eligible */
    public function resolve(string $requirementId, string $taskId, string $factType, array $eligible): ConflictResolution
    {
        $groups = [];
        foreach ($eligible as $item) {
            $subject = $item->evidence->subject;
            $groups[$subject === null ? '-' : $subject['type'].':'.$subject['id']][] = $item;
        }

        $winners = $losers = $contested = $rules = [];
        $status = ConflictStatus::NO_CONFLICT;
        foreach ($groups as $items) {
            [$groupStatus, $w, $l, $c, $rule] = $this->resolveGroup($factType, $items);
            array_push($winners, ...$w);
            array_push($losers, ...$l);
            array_push($contested, ...$c);
            if ($rule !== null) {
                $rules[] = $rule;
            }
            if ($groupStatus === ConflictStatus::UNRESOLVED_CONFLICT
                || ($groupStatus === ConflictStatus::RESOLVED && $status === ConflictStatus::NO_CONFLICT)) {
                $status = $groupStatus;
            }
        }

        return new ConflictResolution($requirementId, $taskId, $factType, $status, $winners, $losers, $contested,
            $rules === [] ? null : implode('; ', array_unique($rules)));
    }

    /**
     * @param  list<AssessedEvidence>  $items
     * @return array{0: ConflictStatus, 1: list<AssessedEvidence>, 2: list<AssessedEvidence>, 3: list<AssessedEvidence>, 4: ?string}
     */
    private function resolveGroup(string $factType, array $items): array
    {
        $passages = array_values(array_filter($items, fn (AssessedEvidence $a) => $a->evidence->normalizedValue() === null));
        $comparable = array_values(array_filter($items, fn (AssessedEvidence $a) => $a->evidence->normalizedValue() !== null));
        usort($comparable, fn (AssessedEvidence $a, AssessedEvidence $b) => $a->rank <=> $b->rank);

        if (count($this->values($comparable)) <= 1) {
            return [ConflictStatus::NO_CONFLICT, [...$comparable, ...$passages], [], [], null];
        }

        $candidates = [
            'explicit_supersedes' => array_filter($comparable, fn (AssessedEvidence $a) => $a->temporal === TemporalStatus::CURRENT
                && in_array($factType, $a->evidence->supersedes, true)),
            'current_exception_over_generic' => array_filter($comparable, fn (AssessedEvidence $a) => $a->evidence->scope === 'exception'
                && $a->temporal === TemporalStatus::CURRENT),
        ];
        $strongest = $comparable[0]->rank;
        $candidates['authority'] = array_filter($comparable, fn (AssessedEvidence $a) => $a->rank === $strongest);

        foreach ($candidates as $rule => $preferred) {
            $values = $this->values($preferred);
            if (count($values) !== 1) {
                if (count($values) > 1) {
                    // The deciding class disagrees with itself: nothing below may overrule it.
                    break;
                }

                continue;
            }
            $value = $values[0];
            $won = array_values(array_filter($comparable, fn (AssessedEvidence $a) => $a->evidence->normalizedValue() === $value));
            $lost = array_values(array_filter($comparable, fn (AssessedEvidence $a) => $a->evidence->normalizedValue() !== $value));
            $label = $rule === 'authority'
                ? 'authority: '.$won[0]->evidence->authorityClass.' over '.implode(', ', array_unique(array_map(fn ($a) => $a->evidence->authorityClass, $lost)))
                : $rule;

            return [ConflictStatus::RESOLVED, [...$won, ...$passages], $lost, [], $label];
        }

        return [ConflictStatus::UNRESOLVED_CONFLICT, $passages, [], $comparable, 'no rule separates '.count($this->values($comparable)).' values'];
    }

    /** @param iterable<AssessedEvidence> $items  @return list<string> */
    private function values(iterable $items): array
    {
        $values = [];
        foreach ($items as $item) {
            $values[$item->evidence->normalizedValue()] = true;
        }

        return array_map('strval', array_keys($values));
    }
}
