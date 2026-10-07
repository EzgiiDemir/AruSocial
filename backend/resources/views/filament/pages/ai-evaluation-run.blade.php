{{--
    One evaluation run. Failures are grouped by the earliest pipeline stage
    that failed, in pipeline order, so the first group is the first thing
    to fix. Snapshots come from AskTrace and are already redacted.
--}}
@php($run = $this->getRecord())
@php($m = $run->metrics ?? [])
@php($groups = $this->failuresByStage())
@php($json = fn ($v) => json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
<x-filament-panels::page>
    <x-filament::section>
        <div class="grid grid-cols-2 gap-4 text-center md:grid-cols-4">
            <div><div class="text-2xl font-semibold text-success-600">{{ $run->passed }}</div><div class="text-xs text-gray-500">{{ __('panel.aicad_runs.passed') }}</div></div>
            <div><div class="text-2xl font-semibold {{ $run->failed ? 'text-danger-600' : '' }}">{{ $run->failed }}</div><div class="text-xs text-gray-500">{{ __('panel.aicad_runs.failed') }}</div></div>
            <div><div class="text-2xl font-semibold">{{ $run->skipped }}</div><div class="text-xs text-gray-500">{{ __('panel.aicad_runs.skipped') }}</div></div>
            <div><div class="text-2xl font-semibold">{{ $run->duration_ms === null ? '—' : round($run->duration_ms / 1000, 1).' s' }}</div><div class="text-xs text-gray-500">{{ __('panel.aicad_tests.duration') }}</div></div>
        </div>
        <div class="mt-4 flex flex-wrap gap-2 text-xs">
            <x-filament::badge :color="$run->status === 'finished' ? 'success' : ($run->status === 'failed' ? 'danger' : 'warning')">{{ $run->status }}</x-filament::badge>
            <x-filament::badge color="gray">{{ $run->mode }} · {{ $run->scope['label'] ?? '' }}</x-filament::badge>
            <x-filament::badge color="gray">commit {{ substr((string) $run->git_commit, 0, 8) ?: '—' }}</x-filament::badge>
            <x-filament::badge color="gray">{{ __('panel.aicad_runs.model') }} {{ $run->local_model ?: '—' }} · emb {{ $run->embedding_model ?: '—' }}</x-filament::badge>
            <x-filament::badge color="gray">retrieval {{ $run->retrieval_fingerprint }} · prompt {{ $run->prompt_fingerprint }}</x-filament::badge>
        </div>
        @if ($run->error)
            <p class="mt-3 text-sm text-danger-600">{{ $run->error }}</p>
        @endif
        @if (in_array($run->status, ['queued', 'running'], true))
            <p class="mt-3 text-sm text-gray-500" wire:poll.5s>{{ __('panel.aicad_runs.in_progress') }}</p>
        @endif
    </x-filament::section>

    @if ($m)
        <x-filament::section>
            <x-slot name="heading">{{ __('panel.aicad_runs.metrics') }}</x-slot>
            <div class="grid grid-cols-1 gap-4 text-sm md:grid-cols-3">
                <div>
                    <div class="mb-1 font-medium">{{ __('panel.aicad_runs.by_category') }}</div>
                    @foreach ($m['categories'] ?? [] as $category => $c)
                        <div class="flex justify-between"><span>{{ $category }}</span><span>{{ $c['passed'] }}/{{ $c['total'] }}</span></div>
                    @endforeach
                </div>
                <div>
                    <div class="mb-1 font-medium">{{ __('panel.aicad_runs.retrieval') }}</div>
                    @foreach ([1, 3, 5, 10] as $k)
                        @php($h = $m['retrieval']['hit@'.$k] ?? null)
                        @if ($h && $h['total'])<div class="flex justify-between"><span>hit@{{ $k }}</span><span>{{ $h['hits'] }}/{{ $h['total'] }} ({{ round($h['rate'] * 100, 1) }}%)</span></div>@endif
                    @endforeach
                    <div class="flex justify-between"><span>{{ __('panel.aicad_runs.in_prompt') }}</span><span>{{ $m['retrieval']['prompt_included'] ?? 0 }}</span></div>
                    <div class="flex justify-between"><span>{{ __('panel.aicad_runs.dropped') }}</span><span>{{ $m['retrieval']['prompt_dropped'] ?? 0 }}</span></div>
                    <div class="flex justify-between"><span>{{ __('panel.aicad_runs.lexical_only') }}</span><span>{{ $m['retrieval']['found_lexical_only'] ?? 0 }}</span></div>
                    <div class="flex justify-between"><span>{{ __('panel.aicad_runs.irrelevant_cited') }}</span><span>{{ $m['retrieval']['irrelevant_cited'] ?? 0 }}</span></div>
                </div>
                <div>
                    <div class="mb-1 font-medium">{{ __('panel.aicad_runs.latency') }}</div>
                    @foreach (['retrieval', 'full'] as $mode)
                        @if (($m['latency'][$mode]['n'] ?? 0) > 0)
                            <div class="flex justify-between"><span>{{ $mode }} (n={{ $m['latency'][$mode]['n'] }})</span><span>{{ $m['latency'][$mode]['median'] }} / {{ $m['latency'][$mode]['p95'] }} ms</span></div>
                        @endif
                    @endforeach
                    <div class="text-xs text-gray-500">median / p95</div>
                </div>
            </div>
        </x-filament::section>
    @endif

    @if ($groups)
        <x-filament::section>
            <x-slot name="heading">{{ __('panel.aicad_runs.failures_by_stage') }}</x-slot>
            <div class="flex flex-wrap gap-2">
                @foreach ($groups as $stage => $items)
                    <x-filament::badge color="danger">{{ str_replace('_', ' ', $stage) }}: {{ $items->count() }}</x-filament::badge>
                @endforeach
            </div>
        </x-filament::section>

        @foreach ($groups as $stage => $items)
            <x-filament::section collapsible>
                <x-slot name="heading">{{ str_replace('_', ' ', $stage) }} ({{ $items->count() }})</x-slot>
                @foreach ($items as $r)
                    @php($s = $r->snapshot ?? [])
                    <details class="border-t border-gray-200 py-2 first:border-t-0 dark:border-white/10">
                        <summary class="cursor-pointer text-sm">
                            <span class="font-medium">{{ $r->case_name }}</span>
                            <span class="text-gray-500">— {{ $r->question }}</span>
                            <span class="text-xs text-gray-400">{{ $r->duration_ms }} ms</span>
                        </summary>
                        <div class="mt-2 space-y-2 text-xs">
                            <div>
                                <div class="font-medium text-danger-600">{{ __('panel.aicad_runs.failed_assertions') }}</div>
                                @foreach ($r->failed_assertions ?? [] as $f)
                                    <div class="font-mono">[{{ str_replace('_', ' ', $f['stage']) }}] {{ $f['expected'] }}<br>→ {{ $json($f['actual']) }}</div>
                                @endforeach
                            </div>
                            @if ($s['follow_up'] ?? null)<div><span class="text-gray-500">follow-up:</span> {{ $s['follow_up']['retrieval_query'] ?? '' }}</div>@endif
                            @if ($s['routing'] ?? null)<div><span class="text-gray-500">planner:</span> domains {{ implode(', ', $s['routing']['domains']) ?: '—' }} · tools {{ implode(', ', $s['routing']['tools']) ?: '—' }} @if ($s['routing']['fallback']) · <span class="text-danger-600">fallback</span>@endif</div>@endif
                            @if ($s['entities'] ?? null)<div><span class="text-gray-500">entities:</span> @foreach ($s['entities'] as $e){{ $e['type'] }}:{{ $e['id'] }} ({{ $e['method'] }}{{ ($e['ambiguous'] ?? false) ? ', ambiguous' : '' }}) @endforeach</div>@endif
                            <div><span class="text-gray-500">served by:</span> {{ $s['served_by'] ?? '—' }} · places {{ implode(', ', $s['operational']['place_ids'] ?? []) ?: '—' }} · route {{ ($s['operational']['route'] ?? false) ? 'yes' : 'no' }}</div>
                            @if ($s['knowledge'] ?? null)
                                <div>
                                    <span class="text-gray-500">retrieval:</span>
                                    @foreach (array_slice($s['knowledge'], 0, 5) as $c)
                                        <div>#{{ $c['rank'] }}{{ $c['selected'] ? '★' : '' }} {{ $c['score'] }} · {{ $c['matched_by'] }} · {{ $c['title'] }}</div>
                                    @endforeach
                                </div>
                            @endif
                            @if ($s['citations'] ?? null)<div><span class="text-gray-500">prompt:</span> @foreach ($s['citations'] as $c){{ $c['fate'] }}: {{ \Illuminate\Support\Str::limit($c['title'], 40) }}; @endforeach</div>@endif
                            @if ($s['model'] ?? null)<div><span class="text-gray-500">model:</span> {{ $json($s['model']) }}</div>@endif
                            @if ($s['grounding'] ?? null)<div><span class="text-gray-500">grounding:</span> {{ $json($s['grounding']) }}</div>@endif
                            @if ($s['answer'] ?? null)<div><span class="text-gray-500">answer:</span> {{ $s['answer'] }}</div>@endif
                            <div class="flex gap-3">
                                <a href="{{ $this->playgroundUrl($r) }}" class="text-primary-600 hover:underline">{{ __('panel.aicad_runs.open_playground') }}</a>
                                <span class="font-mono text-gray-400">trace {{ $r->trace_id }}</span>
                            </div>
                        </div>
                    </details>
                @endforeach
            </x-filament::section>
        @endforeach
    @endif

    @if ($this->skipped()->isNotEmpty())
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">{{ __('panel.aicad_runs.skipped') }} ({{ $this->skipped()->count() }})</x-slot>
            @foreach ($this->skipped() as $r)
                <div class="text-xs"><span class="font-medium">{{ $r->case_name }}</span> — {{ $r->snapshot['skip_reason'] ?? '' }}</div>
            @endforeach
        </x-filament::section>
    @endif
</x-filament-panels::page>
