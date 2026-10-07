<?php

namespace App\Services\Ai\Planning;

/**
 * Where can AICAD currently obtain each requirement? Deterministic policy
 * over ProviderRegistry: the first registered provider for the fact type.
 *
 * Nothing else can choose a provider. A requirement whose fact type no
 * provider declares gets a null route (and its task fails, honestly) —
 * a provider name arriving from anywhere else, including a planner or a
 * crawled page, is never used.
 */
final class ProviderRouter
{
    public function __construct(private readonly ProviderRegistry $registry) {}

    /**
     * @param  list<EvidenceRequirement>  $requirements
     * @return list<ProviderRoute>
     */
    public function route(array $requirements): array
    {
        return array_map(function (EvidenceRequirement $r): ProviderRoute {
            $provider = $this->registry->providersFor($r->factType)[0] ?? null;

            return new ProviderRoute($r->id, $r->taskId, $r->factType, $provider);
        }, $requirements);
    }

    /**
     * Agent tools the routed providers need in the prompt.
     *
     * @param  list<ProviderRoute>  $routes
     * @return list<string>
     */
    public function agentTools(array $routes): array
    {
        $tools = [];
        foreach ($routes as $route) {
            foreach (ProviderRegistry::AGENT_TOOLS[$route->factType] ?? [] as $tool) {
                $tools[$tool] = true;
            }
        }

        return array_keys($tools);
    }
}
