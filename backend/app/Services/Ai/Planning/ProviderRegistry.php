<?php

namespace App\Services\Ai\Planning;

/**
 * Which evidence each existing AICAD capability can supply, in preference
 * order. A capability is a name for something AICAD already has — not a new
 * implementation wrapped around it:
 *
 *  campus_operational  canonical campus rows read by AskOperations,
 *                      PlaceResolver and the agent's tools (places, offices
 *                      and their hours, food venues and menus, events, clubs)
 *  structured_facts    knowledge_facts (programme facts with provenance)
 *  official_knowledge  KnowledgeBase over crawled official pages
 *  routing_service     RoutingService (OSRM walking routes)
 *  request_context     the client's current location in the request
 *
 * The planner never sees these names; ProviderRouter is the only reader.
 */
final class ProviderRegistry
{
    /** @var array<string, list<string>> provider => fact types it can satisfy */
    public const CAPABILITIES = [
        'campus_operational' => ['current_opening_hours', 'place_coordinates', 'food_places', 'current_menu',
            'current_events', 'club_social_profile', 'contact_details'],
        'structured_facts' => ['program_language', 'programme_duration'],
        'official_knowledge' => ['required_documents', 'program_language', 'academic_date'],
        'routing_service' => ['routing'],
        'request_context' => ['user_location'],
    ];

    /**
     * Existing agent tools that put each provider's data in front of the
     * model for a fact type — the compatibility layer between a planned
     * question and the current answer architecture.
     *
     * @var array<string, list<string>>
     */
    public const AGENT_TOOLS = [
        'current_opening_hours' => ['services', 'places'],
        'place_coordinates' => ['places'],
        'food_places' => ['food'],
        'current_menu' => ['food'],
        'current_events' => ['events'],
        'club_social_profile' => ['clubs'],
        'program_language' => ['programs'],
        'programme_duration' => ['programs'],
    ];

    /** Providers able to satisfy a fact type, in preference order. @return list<string> */
    public function providersFor(string $factType): array
    {
        return array_keys(array_filter(self::CAPABILITIES, fn (array $facts) => in_array($factType, $facts, true)));
    }

    public function exists(string $provider): bool
    {
        return isset(self::CAPABILITIES[$provider]);
    }
}
