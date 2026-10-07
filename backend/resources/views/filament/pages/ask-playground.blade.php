{{--
    Search Playground. One panel per Ask stage, in the order the controller
    runs them, so the first panel that looks wrong is where the bug is.
    The trace never contains the system prompt or PersonalContext (AskTrace
    redacts them), only sizes and decisions.
--}}
@php($json = fn ($v) => json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
<x-filament-panels::page>
    <form wire:submit="run">
        {{ $this->form }}
    </form>

    @if ($report)
        @php($result = $report['result'] ?? [])
        @php($routing = $this->stage('routing'))
        @php($entities = $this->stage('entities'))
        @php($followUp = $this->stage('follow_up'))
        @php($operational = $this->stage('operational'))
        @php($direct = $this->stage('direct'))
        @php($semantic = $this->stage('knowledge.semantic'))
        @php($search = $this->stage('knowledge.search'))
        @php($candidates = $this->stage('knowledge.candidates')['candidates'] ?? [])
        @php($budget = $this->stage('prompt.budget'))
        @php($web = $this->stage('web_research'))
        @php($model = $this->stage('model'))
        @php($grounding = $this->stage('grounding'))

        <x-filament::section>
            <x-slot name="heading">{{ __('panel.playground.summary') }}</x-slot>
            <div class="flex flex-wrap gap-2 text-sm">
                <x-filament::badge color="gray">{{ $report['mode'] }}</x-filament::badge>
                @if (isset($result['would_serve']))
                    <x-filament::badge color="info">{{ __('panel.playground.would_serve') }}: {{ $result['would_serve'] }}</x-filament::badge>
                @endif
                @if (isset($result['aiMode']))
                    <x-filament::badge color="info">aiMode: {{ $result['aiMode'] }}</x-filament::badge>
                @endif
                @if ($routing['fallback'] ?? false)
                    <x-filament::badge color="danger">{{ __('panel.playground.routing_fallback') }}</x-filament::badge>
                @endif
                <span class="font-mono text-xs text-gray-500">{{ $report['trace_id'] }}</span>
            </div>
            @if (isset($result['error']))
                <p class="mt-3 text-sm text-danger-600">{{ $result['error'] }}</p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">1 · {{ __('panel.playground.follow_up') }}</x-slot>
            <dl class="space-y-1 text-sm">
                <div><dt class="inline text-gray-500">{{ __('panel.playground.question') }}:</dt> <dd class="inline">{{ $followUp['prompt'] ?? '' }}</dd></div>
                <div><dt class="inline text-gray-500">{{ __('panel.playground.retrieval_query') }}:</dt> <dd class="inline font-medium">{{ $followUp['retrieval_query'] ?? '' }}</dd>
                    @if ($followUp['rewritten'] ?? false) <x-filament::badge color="warning" class="ml-1 inline-flex">{{ __('panel.playground.rewritten') }}</x-filament::badge> @endif
                </div>
                <div><dt class="inline text-gray-500">{{ __('panel.playground.normalized') }}:</dt> <dd class="inline font-mono">{{ $routing['normalized'] ?? '' }}</dd></div>
            </dl>
        </x-filament::section>

        @php($planMode = $this->stage('planning_mode'))
        @if ($planMode)
            {{-- Phase 3A: task planning and evidence routing, by task id. --}}
            @php($tasks = $this->stage('task_plan')['tasks'] ?? [])
            @php($requirements = $this->stage('evidence_requirements')['requirements'] ?? [])
            @php($routes = collect($this->stage('provider_routes')['routes'] ?? [])->keyBy('requirement_id'))
            @php($states = collect($this->stage('task_execution')['states'] ?? [])->keyBy('task_id'))
            @php($finals = $this->stage('final_entities')['entities'] ?? [])
            @php($resolutions = $this->stage('preliminary_entities')['resolutions'] ?? [])
            @php($references = $this->stage('conversation_reference')['references'] ?? [])
            <x-filament::section>
                <x-slot name="heading">{{ __('panel.playground.planning') }}</x-slot>
                <div class="flex flex-wrap gap-2 text-sm">
                    <x-filament::badge :color="$planMode['mode'] === 'planned' ? 'info' : 'gray'">{{ $planMode['mode'] }}</x-filament::badge>
                    @if ($planMode['handler'] ?? null)<x-filament::badge color="gray">{{ $planMode['handler'] }}</x-filament::badge>@endif
                    <span class="text-xs text-gray-500">{{ $planMode['reason'] }}</span>
                    @if ($planMode['timings'] ?? null)<span class="font-mono text-xs text-gray-400">{{ collect($planMode['timings'])->map(fn ($v, $k) => "$k $v ms")->implode(' · ') }}</span>@endif
                </div>
                @if ($resolutions || $references)
                    <div class="mt-2 space-y-0.5 text-xs">
                        @foreach ($resolutions as $r)
                            <div><span class="font-mono">"{{ $r['mention']['surface'] }}"</span>
                                <x-filament::badge class="inline-flex" :color="$r['status'] === 'RESOLVED' ? 'success' : 'warning'">{{ $r['status'] }}</x-filament::badge>
                                @foreach ($r['candidates'] as $c){{ $c['entity_type'] }}:{{ $c['entity_id'] }} ({{ $c['match_type'] }}, resolver_score {{ $c['resolver_score'] }}) @endforeach
                                @if ($r['candidate_margin'] !== null) · margin {{ $r['candidate_margin'] }} @endif</div>
                        @endforeach
                        @foreach ($references as $surface => $ref)
                            <div><span class="font-mono">"{{ $surface }}"</span> → {{ $ref['source'] }}: "{{ $ref['mention'] }}" ({{ implode(', ', $ref['candidates']) }})</div>
                        @endforeach
                    </div>
                @endif
                @if ($tasks)
                    <table class="mt-3 w-full text-left text-xs">
                        <thead class="uppercase text-gray-500"><tr><th class="py-1">task</th><th>type</th><th>depends on</th><th>requirements → provider</th><th>state</th></tr></thead>
                        <tbody>
                            @foreach ($tasks as $t)
                                @php($s = $states[$t['id']] ?? null)
                                <tr class="border-t border-gray-200 align-top dark:border-white/10">
                                    <td class="py-1 font-mono">{{ $t['id'] }}</td>
                                    <td>{{ $t['type'] }}@if ($t['entity_mentions'])<br><span class="text-gray-500">{{ implode(', ', $t['entity_mentions']) }}</span>@endif</td>
                                    <td class="font-mono">{{ implode(', ', $t['depends_on']) ?: '—' }}</td>
                                    <td>@foreach (array_filter($requirements, fn ($r) => $r['task_id'] === $t['id']) as $r){{ $r['fact_type'] }} → {{ $routes[$r['id']]['provider'] ?? 'not routed' }}<br>@endforeach</td>
                                    <td>@if ($s)<x-filament::badge class="inline-flex" :color="match ($s['state']) { 'COMPLETED' => 'success', 'FAILED' => 'danger', 'BLOCKED' => 'warning', default => 'gray' }">{{ $s['state'] }}</x-filament::badge> <span class="text-gray-500">{{ $s['reason'] }}</span>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
                @if ($finals)
                    <div class="mt-2 space-y-0.5 text-xs">
                        @foreach ($finals as $f)
                            <div><span class="font-mono">{{ $f['task_id'] }}</span> "{{ $f['mention'] }}": {{ $f['preliminary'] ?? '—' }} → <span class="font-medium">{{ $f['final'] }}</span> <span class="text-gray-500">({{ $f['reason'] }})</span></div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        @endif

        @php($coverage = $this->stage('requirement_coverage'))
        @if ($coverage && isset($coverage['outcome']))
            {{-- Phase 3B: per task, each requirement's evidence with provenance, validity, policy, conflicts and prompt fate. --}}
            @php($evidenceItems = collect($this->stage('provider_evidence')['evidence'] ?? [])->groupBy('requirement_id'))
            @php($assessed = collect($this->stage('evidence_policy')['items'] ?? [])->keyBy('evidence_id'))
            @php($consolidated = collect($this->stage('evidence_consolidation')['requirements'] ?? [])->keyBy('requirement_id'))
            @php($fates = collect($this->stage('evidence_budget')['items'] ?? [])->whereNotNull('evidence_id')->keyBy('evidence_id'))
            @php($year = $this->stage('temporal_evaluation')['academic_year'] ?? null)
            <x-filament::section>
                <x-slot name="heading">{{ __('panel.playground.evidence') }}</x-slot>
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <x-filament::badge :color="match ($coverage['outcome']) { 'COMPLETE' => 'success', 'PARTIAL' => 'warning', default => 'danger' }">{{ $coverage['outcome'] }}</x-filament::badge>
                    @foreach ($coverage['classes'] ?? [] as $class)<x-filament::badge color="gray">{{ $class }}</x-filament::badge>@endforeach
                    @if ($coverage['timings'] ?? null)<span class="font-mono text-xs text-gray-400">{{ collect($coverage['timings'])->map(fn ($v, $k) => "$k $v ms")->implode(' · ') }}</span>@endif
                    @if ($year && $year['stale'])<x-filament::badge color="danger">academic year {{ $year['active_label'] }} is active but ended {{ $year['ends_on'] }}</x-filament::badge>@endif
                </div>
                <table class="mt-3 w-full text-left text-xs">
                    <thead class="uppercase text-gray-500"><tr><th class="py-1">task</th><th>requirement</th><th>coverage</th><th>evidence</th></tr></thead>
                    <tbody>
                        @foreach ($coverage['tasks'] ?? [] as $task)
                            @foreach (collect($coverage['requirements'] ?? [])->where('task_id', $task['task_id']) as $req)
                                @php($c = $consolidated[$req['requirement_id']] ?? [])
                                <tr class="border-t border-gray-200 align-top dark:border-white/10">
                                    <td class="py-1">@if ($loop->first)<span class="font-mono">{{ $task['task_id'] }}</span> {{ $task['type'] }}<br>
                                        <x-filament::badge class="inline-flex" :color="match ($task['state']) { 'COMPLETED' => 'success', 'FAILED' => 'danger', 'BLOCKED' => 'warning', default => 'gray' }">{{ $task['state'] }}</x-filament::badge>@endif</td>
                                    <td><span class="font-mono">{{ $req['requirement_id'] }}</span> {{ $req['fact_type'] }}</td>
                                    <td><x-filament::badge class="inline-flex" :color="match ($req['coverage']) { 'satisfied' => 'success', 'conflicting' => 'warning', 'not_applicable' => 'gray', default => 'danger' }">{{ $req['coverage'] }}</x-filament::badge>
                                        @if (($c['conflict'] ?? 'NO_CONFLICT') !== 'NO_CONFLICT')<br>{{ $c['conflict'] }}@if ($c['rule'] ?? null) <span class="text-gray-500">({{ $c['rule'] }})</span>@endif @endif
                                        @if ($req['reasons'] ?? null)<br><span class="text-gray-500">{{ implode(', ', $req['reasons']) }}</span>@endif
                                        @if ($req['data_gap'] ?? false)<br><span class="text-warning-600">data gap</span>@endif</td>
                                    <td>@foreach ($evidenceItems[$req['requirement_id']] ?? [] as $e)
                                        @php($a = $assessed[$e['evidence_id']] ?? null)
                                        <div><span class="font-mono">{{ $e['evidence_id'] }}</span> {{ $e['status'] }}@if ($e['reason'] ?? null) ({{ $e['reason'] }})@endif
                                            @if ($e['authority_class'] ?? null) · {{ $e['authority_class'] }}@endif
                                            @if ($e['source']['url'] ?? null) · <a class="underline" href="{{ $e['source']['url'] }}" target="_blank" rel="noopener">{{ \Illuminate\Support\Str::limit($e['source']['title'] ?? $e['source']['url'], 50) }}</a>@elseif ($e['source']['source_id'] ?? null) · <span class="font-mono">{{ $e['source']['source_id'] }}</span>@endif
                                            @if (isset($e['value']) && is_scalar($e['value']) && ($e['value_type'] ?? '') !== 'document_passage') · <span class="font-medium">{{ \Illuminate\Support\Str::limit((string) $e['value'], 60) }}</span>@endif
                                            @if ($a) · {{ $a['temporal'] }} · <span class="{{ $a['eligible'] ? 'text-success-600' : 'text-danger-600' }}">{{ $a['eligible'] ? 'eligible' : $a['policy_reason'] }}</span>@endif
                                            @if (in_array($e['evidence_id'], $c['losers'] ?? [], true)) · <span class="text-gray-500">set aside</span>@endif
                                            @if ($fates->has($e['evidence_id'])) · prompt: {{ $fates[$e['evidence_id']]['fate'] }}@endif
                                        </div>
                                    @endforeach</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </x-filament::section>
        @endif

        @php($answerPlan = $this->stage('answer_plan'))
        @if ($answerPlan && isset($answerPlan['tasks']))
            {{-- Phase 3C: per task, candidate facts → validation → supported facts → answer plan → claims. --}}
            @php($factReqs = collect($this->stage('requirement_fact_status')['requirements'] ?? []))
            @php($factCandidates = collect($this->stage('candidate_facts')['candidates'] ?? [])->keyBy('candidate_id'))
            @php($supported = collect($this->stage('supported_facts')['facts'] ?? [])->keyBy('fact_id'))
            @php($usage = $this->stage('generation_fact_usage'))
            @php($verification = $this->stage('claim_verification'))
            <x-filament::section>
                <x-slot name="heading">{{ __('panel.playground.facts') }}</x-slot>
                <div class="flex flex-wrap items-center gap-2 text-sm">
                    <x-filament::badge :color="match ($answerPlan['outcome']) { 'COMPLETE' => 'success', 'PARTIAL' => 'warning', default => 'danger' }">{{ $answerPlan['outcome'] }}</x-filament::badge>
                    <x-filament::badge color="gray">{{ $this->stage('generation_input') ? 'generated from facts' : 'facts built, Phase 3B generation' }}</x-filament::badge>
                    @if ($verification)
                        <x-filament::badge :color="$verification['final_unsupported'] === 0 && ! $verification['answer_withheld'] ? 'success' : 'danger'">claims: {{ $verification['final_claims'] ?? 0 }} supported · {{ count($verification['first_draft_unsupported']) }} unsupported in draft · {{ $verification['regenerated'] ? 'regenerated' : 'no regeneration' }} · {{ $verification['withheld_sentences'] }} withheld</x-filament::badge>
                    @endif
                </div>
                <table class="mt-3 w-full text-left text-xs">
                    <thead class="uppercase text-gray-500"><tr><th class="py-1">task</th><th>requirement</th><th>candidates → facts</th><th>rejected</th></tr></thead>
                    <tbody>
                        @foreach ($answerPlan['tasks'] as $task)
                            @foreach ($factReqs->where('task_id', $task['task_id']) as $req)
                                <tr class="border-t border-gray-200 align-top dark:border-white/10">
                                    <td class="py-1">@if ($loop->first)<span class="font-mono">{{ $task['task_id'] }}</span> {{ $task['task_type'] }}<br>
                                        <x-filament::badge class="inline-flex" :color="match ($task['status']) { 'supported' => 'success', 'partial', 'context_missing', 'conflicting' => 'warning', 'not_applicable' => 'gray', default => 'danger' }">{{ $task['status'] }}</x-filament::badge>@endif</td>
                                    <td><span class="font-mono">{{ $req['requirement_id'] }}</span> {{ $req['fact_type'] }}<br>
                                        <x-filament::badge class="inline-flex" :color="$req['status'] === 'SUPPORTED' ? 'success' : ($req['status'] === 'NOT_APPLICABLE' ? 'gray' : 'danger')">{{ $req['status'] }}</x-filament::badge>
                                        @if ($req['conflict_values'] ?? null)<br>{{ implode(' / ', $req['conflict_values']) }}@endif</td>
                                    <td>@foreach ($req['candidate_ids'] ?? [] as $cid)
                                            @php($c = $factCandidates[$cid] ?? null)
                                            <div><span class="font-mono">{{ $cid }}</span> {{ $c['method'] ?? '' }} ← {{ implode(', ', $c['evidence_ids'] ?? []) }}</div>
                                        @endforeach
                                        @foreach ($req['fact_ids'] ?? [] as $fid)
                                            @php($f = $supported[$fid] ?? null)
                                            <div class="text-success-600"><span class="font-mono">{{ $fid }}</span> {{ $f['fact_type'] ?? '' }} = <span class="font-medium">{{ \Illuminate\Support\Str::limit(is_scalar($f['value'] ?? null) ? (string) $f['value'] : json_encode($f['value'] ?? null, JSON_UNESCAPED_UNICODE), 80) }}</span>
                                                <span class="text-gray-500">({{ $f['validation']['method'] ?? '' }}: {{ $f['validation']['reason'] ?? '' }})</span>
                                                @if ($f['span'] ?? null)<br><span class="text-gray-500">“{{ $f['span'] }}”</span>@endif
                                                @if ($usage && in_array($fid, $usage['used_fact_ids'] ?? [], true)) · <span class="font-medium">used</span>@endif</div>
                                        @endforeach</td>
                                    <td class="text-gray-500">@foreach ($req['rejected'] ?? [] as $x)<div>{{ $x['candidate_id'] ?? $x['evidence_id'] ?? '' }}: {{ $x['reason'] }}</div>@endforeach</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
                @if ($usage)
                    <div class="mt-3 space-y-0.5 text-xs">
                        {{-- Every final sentence with its class: SUPPORTED_BY_FACT, PRESENTATION_ONLY, UNCERTAIN (kept, the main rollout signal). Diagnostic view only; never aggregated. --}}
                        @foreach ($usage['sentences'] ?? [] as $sentence)
                            <div><x-filament::badge class="inline-flex" :color="match ($sentence['class']) { 'SUPPORTED_BY_FACT' => 'success', 'PRESENTATION_ONLY' => 'gray', 'UNCERTAIN' => 'warning', default => 'danger' }">{{ $sentence['class'] }}</x-filament::badge>
                                “{{ \Illuminate\Support\Str::limit($sentence['text'], 140) }}”
                                @if ($sentence['fact_ids']) → <span class="font-mono">{{ implode(', ', $sentence['fact_ids']) }}</span>@endif
                                @if ($sentence['kinds']) <span class="text-gray-500">({{ implode(', ', $sentence['kinds']) }})</span>@endif
                                @if ($sentence['class'] === 'UNCERTAIN') <span class="text-gray-500">(campus subject, no fact, nothing checkable)</span>@endif</div>
                        @endforeach
                        @foreach ($verification['first_draft_unsupported'] ?? [] as $u)
                            <div class="text-danger-600">✗ {{ $u['kind'] }}: {{ $u['detail'] }}</div>
                        @endforeach
                        @if ($usage['not_cited'] ?? null)<div class="text-gray-500">not cited (no used fact): {{ implode(', ', $usage['not_cited']) }}</div>@endif
                    </div>
                @endif
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">2 · {{ __('panel.playground.paths') }}</x-slot>
            <div class="flex flex-wrap gap-2 text-sm">
                <x-filament::badge :color="($operational['answered'] ?? false) ? 'success' : 'gray'">
                    {{ __('panel.playground.operational') }}: {{ ($operational['answered'] ?? false) ? __('panel.knowledge_documents.yes') : __('panel.knowledge_documents.no') }}
                </x-filament::badge>
                <x-filament::badge color="gray">places {{ $operational['places'] ?? 0 }} · events {{ $operational['events'] ?? 0 }} · route {{ ($operational['route'] ?? false) ? '✓' : '—' }}</x-filament::badge>
                <x-filament::badge :color="($direct['answered'] ?? false) ? 'success' : 'gray'">
                    {{ __('panel.playground.direct') }}: {{ ($direct['answered'] ?? false) ? __('panel.knowledge_documents.yes') : __('panel.knowledge_documents.no') }}
                </x-filament::badge>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">3 · {{ __('panel.playground.routing') }}</x-slot>
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-gray-500"><tr><th class="py-1">{{ __('panel.playground.domain') }}</th><th>{{ __('panel.playground.score') }}</th><th>{{ __('panel.playground.reasons') }}</th></tr></thead>
                <tbody>
                    @forelse ($routing['domains'] ?? [] as $domain => $info)
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="py-1 font-medium">{{ $domain }}</td>
                            <td>{{ round($info['score'], 2) }}</td>
                            <td class="font-mono text-xs">{{ implode(', ', $info['reasons']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="py-1 text-danger-600">{{ __('panel.playground.no_domain') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
            <p class="mt-2 text-sm"><span class="text-gray-500">{{ __('panel.playground.tools') }}:</span> {{ implode(', ', $routing['tools'] ?? []) ?: '—' }}</p>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">4 · {{ __('panel.playground.entities') }}</x-slot>
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-gray-500"><tr><th class="py-1">type</th><th>id</th><th>{{ __('panel.knowledge_documents.title') }}</th><th>matched</th><th>method</th><th>{{ __('panel.playground.score') }}</th></tr></thead>
                <tbody>
                    @forelse ($entities['resolved'] ?? [] as $entity)
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="py-1">{{ $entity['type'] }}</td>
                            <td class="font-mono text-xs">{{ $entity['id'] }}</td>
                            <td>{{ $entity['name'] }}</td>
                            <td class="font-mono text-xs">{{ $entity['matched'] }}</td>
                            <td><x-filament::badge :color="$entity['method'] === 'fuzzy' ? 'warning' : 'success'">{{ $entity['method'] }}</x-filament::badge></td>
                            <td>{{ $entity['score'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-1 text-gray-500">{{ __('panel.playground.no_entities') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">5 · {{ __('panel.playground.database') }}</x-slot>
            @forelse ($this->toolStages() as $tool)
                <details class="border-t border-gray-200 py-1 first:border-t-0 dark:border-white/10">
                    <summary class="cursor-pointer text-sm font-medium">{{ substr($tool['stage'], 5) }} — {{ count($tool['data']['rows'] ?? []) }} {{ __('panel.playground.rows') }}</summary>
                    <ul class="mt-1 space-y-0.5 text-xs text-gray-700 dark:text-gray-300">
                        @foreach ($tool['data']['rows'] ?? [] as $row)
                            <li>{{ $row }}</li>
                        @endforeach
                    </ul>
                </details>
            @empty
                <p class="text-sm text-gray-500">{{ __('panel.playground.no_tools') }}</p>
            @endforelse
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">6 · {{ __('panel.playground.knowledge') }}</x-slot>
            <div class="mb-2 flex flex-wrap gap-2 text-xs">
                @if ($semantic)
                    @if ($semantic['query_embedded'] ?? false)
                        <x-filament::badge color="gray">{{ $semantic['dimensions'] }}d · compared {{ $semantic['compared'] }} · ≥floor {{ $semantic['documents_above_floor'] }} docs</x-filament::badge>
                        @if (($semantic['dimension_mismatch'] ?? 0) > 0)
                            <x-filament::badge color="danger">dimension mismatch: {{ $semantic['dimension_mismatch'] }}</x-filament::badge>
                        @endif
                    @else
                        <x-filament::badge color="danger">{{ __('panel.playground.embedding_failed') }}</x-filament::badge>
                    @endif
                @else
                    <x-filament::badge color="warning">{{ __('panel.playground.no_semantic') }}</x-filament::badge>
                @endif
                @if ($search)
                    <x-filament::badge color="gray">terms: {{ implode(' ', $search['terms']) ?: '—' }}</x-filament::badge>
                    <x-filament::badge color="gray">min_similarity {{ $search['min_similarity'] }} · scored {{ $search['scored_documents'] }}</x-filament::badge>
                @endif
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-gray-500"><tr><th class="py-1">#</th><th>{{ __('panel.playground.score') }}</th><th>{{ __('panel.playground.components') }}</th><th>{{ __('panel.knowledge_documents.title') }}</th></tr></thead>
                    <tbody>
                        @forelse ($candidates as $c)
                            <tr class="border-t border-gray-200 align-top dark:border-white/10 {{ $c['selected'] ? '' : 'opacity-60' }}">
                                <td class="py-1">{{ $c['rank'] }}@if ($c['selected'])<span title="{{ __('panel.playground.sent') }}">★</span>@endif</td>
                                <td class="font-medium">{{ $c['score'] }}</td>
                                <td class="font-mono text-xs">@foreach ($c['parts'] as $k => $v){{ $k }}={{ $v }}<br>@endforeach</td>
                                <td>
                                    <a href="{{ $c['url'] }}" target="_blank" rel="noopener noreferrer" class="text-primary-600 hover:underline">{{ $c['title'] ?: $c['url'] }}</a>
                                    <span class="text-xs text-gray-500">{{ $c['language'] }} · authority {{ $c['authority'] }}@if ($c['stale']) · stale @endif</span>
                                    @if ($c['passage'])<p class="mt-0.5 text-xs text-gray-600 dark:text-gray-400">{{ $c['passage'] }}</p>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="py-1 text-gray-500">{{ __('panel.playground.no_candidates') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">7 · {{ __('panel.playground.budget') }}</x-slot>
            @if ($budget)
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ $budget['context_chars'] ?? 0 }} → {{ $budget['kept_chars'] ?? 0 }} chars
                    (budget {{ $budget['char_budget'] ?? '—' }} chars, room {{ $budget['token_room'] ?? '—' }} tokens)
                </p>
                <div class="mt-2 flex flex-wrap gap-2">
                    @foreach ($budget['blocks'] ?? [] as $block)
                        <x-filament::badge :color="match ($block['fate']) { 'kept' => 'success', 'truncated' => 'warning', default => 'danger' }">
                            {{ $block['id'] }} · {{ $block['chars'] }} · {{ $block['fate'] }}
                        </x-filament::badge>
                    @endforeach
                </div>
                @php($citations = $this->stage('citations')['candidates'] ?? [])
                @if ($citations)
                    {{-- Only kept/truncated sources are cited to the student. --}}
                    <ul class="mt-2 space-y-0.5 text-xs">
                        @foreach ($citations as $c)
                            <li>
                                <x-filament::badge class="inline-flex" :color="match ($c['fate']) { 'kept' => 'success', 'truncated' => 'warning', default => 'danger' }">{{ $c['fate'] }}</x-filament::badge>
                                {{ $c['title'] ?: $c['id'] }} @if ($c['reason']) — {{ $c['reason'] }} @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            @else
                <p class="text-sm text-gray-500">{{ __('panel.playground.not_reached') }}</p>
            @endif
            @if ($web)
                <p class="mt-2 text-sm"><span class="text-gray-500">{{ __('panel.playground.web') }}:</span>
                    {{ ($web['triggered'] ?? false) ? __('panel.knowledge_documents.yes') : __('panel.knowledge_documents.no') }}
                    ({{ ($web['available'] ?? false) ? 'available' : 'not configured' }})</p>
            @endif
        </x-filament::section>

        @if ($model || $grounding)
            <x-filament::section>
                <x-slot name="heading">8 · {{ __('panel.playground.model') }}</x-slot>
                <pre class="whitespace-pre-wrap text-xs">{{ $json(['model' => $model, 'grounding' => $grounding]) }}</pre>
            </x-filament::section>
        @endif

        <x-filament::section>
            <x-slot name="heading">9 · {{ __('panel.playground.result') }}</x-slot>
            @php($answer = $result['answer'] ?? $result['operational_answer'] ?? $result['direct_answer'] ?? null)
            @if ($answer)
                <p class="whitespace-pre-wrap text-sm">{{ $answer }}</p>
            @endif
            <details class="mt-2">
                <summary class="cursor-pointer text-sm text-gray-500">sources / places / events / route</summary>
                <pre class="mt-1 max-h-96 overflow-auto whitespace-pre-wrap text-xs">{{ $json(array_intersect_key($result, array_flip(['sources', 'places', 'events', 'route', 'warnings']))) }}</pre>
            </details>
        </x-filament::section>

        <x-filament::section collapsible collapsed>
            <x-slot name="heading">{{ __('panel.playground.raw') }}</x-slot>
            <pre class="max-h-[32rem] overflow-auto whitespace-pre-wrap text-xs">{{ $json($report['stages']) }}</pre>
        </x-filament::section>
    @endif
</x-filament-panels::page>
