<?php

namespace App\Services\Ai\Planning;

/**
 * What must be known to complete each task. Still provider-free: a
 * requirement says "current opening hours, authoritative, for this
 * subject", never which table or service holds them.
 *
 * Requirement ids derive from the task id (t3 → t3.r1, t3.r2), so the
 * chain task → requirement → route stays traceable without new ids.
 */
final class RequirementBuilder
{
    /**
     * Task type → [fact type, subject, temporal scope, authority]. Subject:
     * `entity` (the task's own subject), `previous` (the output of the task
     * it depends on), `user`, or null.
     *
     * @var array<string, list<array{0: string, 1: ?string, 2: string, 3: string}>>
     */
    public const BY_TASK = [
        'opening_hours' => [['current_opening_hours', 'entity', 'current', 'authoritative']],
        'required_documents' => [['required_documents', 'entity', 'static', 'official']],
        'location' => [['place_coordinates', 'entity', 'static', 'authoritative']],
        'route' => [
            ['place_coordinates', 'entity', 'static', 'authoritative'],
            ['user_location', 'user', 'current', 'contextual'],
            ['routing', null, 'current', 'authoritative'],
        ],
        'current_menu' => [['current_menu', null, 'current', 'authoritative']],
        'current_events' => [['current_events', null, 'current', 'authoritative']],
        'program_language' => [['program_language', null, 'static', 'official']],
        'programme_duration' => [['programme_duration', null, 'static', 'official']],
        'club_social_profile' => [['club_social_profile', 'entity', 'static', 'authoritative']],
        'academic_dates' => [['academic_date', null, 'static', 'official']],
        'contact_details' => [['contact_details', 'entity', 'static', 'authoritative']],
        'find_food_places' => [['food_places', null, 'static', 'authoritative']],
        'filter_open_now' => [['current_opening_hours', 'previous', 'current', 'authoritative']],
        'rank_by_distance' => [
            ['user_location', 'user', 'current', 'contextual'],
            ['place_coordinates', 'previous', 'static', 'authoritative'],
        ],
    ];

    /** @return list<EvidenceRequirement> */
    public function build(TaskPlan $plan): array
    {
        $out = [];
        foreach ($plan->tasks as $task) {
            $specs = self::BY_TASK[$task->type] ?? [];
            // A route after a ranking takes its destination from that task.
            $fromPrevious = $task->dependsOn !== [];
            foreach ($specs as $i => [$fact, $subject, $temporal, $authority]) {
                $ref = match ($subject === 'entity' && $fromPrevious ? 'previous' : $subject) {
                    'entity' => $task->mentions !== [] ? 'mention:'.$task->mentions[0] : null,
                    'previous' => 'task:'.implode(',', $task->dependsOn),
                    'user' => 'user',
                    default => null,
                };
                $out[] = new EvidenceRequirement($task->id.'.r'.($i + 1), $task->id, $fact, $ref, $temporal, $authority);
            }
        }

        return $out;
    }
}
