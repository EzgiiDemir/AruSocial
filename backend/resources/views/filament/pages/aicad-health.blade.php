{{-- AICAD Health. Read-only; no credentials are rendered. Live probes run only on "Check now". --}}
@php($h = $this->health())
@php($queue = $h->queue())
@php($index = $h->index())
@php($run = $h->lastRun())
@php($warnings = $h->dataWarnings())
@php($row = fn ($label, $value, $bad = false) => '<div class="flex justify-between gap-3"><dt class="text-gray-500 dark:text-gray-400">'.e($label).'</dt><dd class="'.($bad ? 'text-danger-600' : 'text-gray-900 dark:text-gray-100').'">'.e($value).'</dd></div>')
<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <x-filament::section>
            <x-slot name="heading">{{ __('panel.aicad_health.queue') }}</x-slot>
            <dl class="space-y-1 text-sm">
                @if ($queue['inspectable'])
                    {!! $row(__('panel.aicad_health.pending'), $queue['pending'].' ('.$queue['pending_aicad'].' AICAD)') !!}
                    {!! $row(__('panel.aicad_health.running'), $queue['running']) !!}
                    {!! $row(__('panel.aicad_health.oldest'), $queue['oldest_pending_seconds'] === null ? '—' : $queue['oldest_pending_seconds'].' s', $queue['stalled']) !!}
                    {!! $row(__('panel.aicad_health.failed_7d'), $queue['failed_aicad_7d'] ?? '—', ($queue['failed_aicad_7d'] ?? 0) > 0) !!}
                    @if ($queue['stalled'])
                        <p class="mt-2 text-sm text-danger-600">{{ __('panel.aicad_health.stalled') }}</p>
                    @endif
                @else
                    <p class="text-sm text-gray-500">{{ __('panel.aicad_health.not_inspectable', ['connection' => $queue['connection']]) }}</p>
                @endif
            </dl>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">{{ __('panel.aicad_health.services') }}</x-slot>
            @if ($probes)
                <dl class="space-y-1 text-sm">
                    @foreach ($probes as $name => $probe)
                        {!! $row($name, ($probe['ok'] ? '✓ ' : '✗ ').$probe['message'], ! $probe['ok']) !!}
                    @endforeach
                </dl>
            @else
                <p class="text-sm text-gray-500">{{ __('panel.aicad_health.probe_hint') }}</p>
            @endif
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">{{ __('panel.aicad_health.index') }}</x-slot>
            <dl class="space-y-1 text-sm">
                {!! $row(__('panel.aicad_health.pages'), number_format($index['pages'])) !!}
                {!! $row(__('panel.aicad_health.passages'), number_format($index['embedded']).' / '.number_format($index['passages']).' embedded', $index['embedded'] < $index['passages']) !!}
                {!! $row(__('panel.aicad_health.last_crawl'), $index['last_crawl'] ? \Illuminate\Support\Carbon::parse($index['last_crawl'])->diffForHumans() : '—') !!}
                {!! $row(__('panel.aicad_health.last_embedding'), $index['last_embedding'] ? \Illuminate\Support\Carbon::parse($index['last_embedding'])->diffForHumans() : '—') !!}
                {!! $row(__('panel.aicad_health.facts'), $index['programme_facts']) !!}
                {!! $row(__('panel.aicad_aliases.nav'), $index['aliases']) !!}
            </dl>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">{{ __('panel.aicad_health.last_run') }}</x-slot>
            @if ($run)
                <dl class="space-y-1 text-sm">
                    {!! $row('#'.$run->id.' '.$run->mode, $run->status, $run->status === 'failed') !!}
                    {!! $row(__('panel.aicad_runs.passed').' / '.__('panel.aicad_runs.failed'), $run->passed.' / '.$run->failed, $run->failed > 0) !!}
                    {!! $row(__('panel.aicad_tests.last_run'), $run->created_at?->diffForHumans()) !!}
                </dl>
                <a href="{{ \App\Filament\Resources\AiEvaluationRuns\AiEvaluationRunResource::getUrl('view', ['record' => $run]) }}" class="mt-2 inline-block text-sm text-primary-600 hover:underline">{{ __('panel.aicad_tests.open_run') }}</a>
            @else
                <p class="text-sm text-gray-500">—</p>
            @endif
        </x-filament::section>
    </div>

    {{-- Phase 3C.1: SupportedFacts rollout. Aggregate counters only (30-day window); no question, answer or prompt is stored. --}}
    @php($rollout = \App\Services\Ai\Facts\SupportedFactsRollout::aggregates())
    @php($rc = $rollout['counters'])
    <x-filament::section>
        <x-slot name="heading">{{ __('panel.aicad_health.rollout') }}</x-slot>
        <p class="mb-2 text-xs text-gray-500">{{ __('panel.aicad_health.rollout_hint') }}</p>
        <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <dl class="space-y-1 text-sm">
                {!! $row(__('panel.aicad_health.rollout_mode'), $rollout['mode']) !!}
                {!! $row(__('panel.aicad_health.rollout_fact_requests'), $rc['supported_facts_requests']) !!}
                {!! $row(__('panel.aicad_health.rollout_legacy_requests'), $rc['legacy_planned_requests']) !!}
                {!! $row(__('panel.aicad_health.rollout_model_calls'), $rollout['model_calls_per_request'] ?? '—') !!}
                {!! $row(__('panel.aicad_health.rollout_latency'), $rollout['latency_ms']['n'] > 0 ? $rollout['latency_ms']['median'].' / '.$rollout['latency_ms']['p95'].' ms (n='.$rollout['latency_ms']['n'].')' : '—') !!}
            </dl>
            <dl class="space-y-1 text-sm">
                {!! $row('COMPLETE', $rc['outcome_complete']) !!}
                {!! $row('PARTIAL', $rc['outcome_partial']) !!}
                {!! $row('UNAVAILABLE', $rc['outcome_unavailable']) !!}
                {!! $row('FAILED', $rc['outcome_failed'], $rc['outcome_failed'] > 0) !!}
            </dl>
            <dl class="space-y-1 text-sm">
                {!! $row(__('panel.aicad_health.rollout_regenerations'), $rc['regenerations'].($rollout['regeneration_rate'] !== null ? ' ('.round($rollout['regeneration_rate'] * 100, 1).'%)' : '')) !!}
                {!! $row(__('panel.aicad_health.rollout_removed'), $rc['unsupported_claims_removed'].' ('.$rc['speculative_claims_removed'].' '.__('panel.aicad_health.rollout_speculative').')') !!}
                {!! $row(__('panel.aicad_health.rollout_withheld'), $rc['answers_withheld'], $rc['answers_withheld'] > 0) !!}
                {!! $row(__('panel.aicad_health.rollout_restated'), $rc['restatements']) !!}
                {!! $row(__('panel.aicad_health.rollout_path_errors'), $rc['path_errors'].' ('.$rc['path_error_legacy_fallbacks'].' → legacy, '.$rc['path_error_deterministic_fallbacks'].' → deterministic)', $rc['path_errors'] > 0) !!}
                {!! $row(__('panel.aicad_health.rollout_shadow'), $rc['shadow_unsupported_claims'].' / '.$rc['shadow_checks']) !!}
            </dl>
        </div>
        @php($pct = fn ($v) => $v === null ? '—' : round($v * 100, 1).'%')
        <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-3">
            <dl class="space-y-1 text-sm">
                {!! $row(__('panel.aicad_health.rollout_uncertain'), $rc['uncertain_sentences'].' / '.$rc['sentences_total'].' ('.$pct($rollout['rates']['uncertain_sentence_rate']).')') !!}
                {!! $row(__('panel.aicad_health.rollout_uncertain_responses'), $rc['responses_with_uncertain'].' ('.$pct($rollout['rates']['uncertain_response_rate']).')') !!}
                {!! $row(__('panel.aicad_health.rollout_uncertain_where'), collect($rollout['uncertain_by_language'])->map(fn ($n, $k) => "$k $n")->implode(', ') ?: '—') !!}
                {!! $row('', collect($rollout['uncertain_by_task_type'])->map(fn ($n, $k) => "$k $n")->implode(', ') ?: '—') !!}
            </dl>
            <dl class="space-y-1 text-sm">
                {!! $row(__('panel.aicad_health.rollout_path_error_rate'), $pct($rollout['rates']['path_error_rate']), ($rollout['rates']['path_error_rate'] ?? 0) > 0) !!}
                {!! $row(__('panel.aicad_health.rollout_claim_failures'), $rc['claim_verification_failures'], $rc['claim_verification_failures'] > 0) !!}
                {!! $row(__('panel.aicad_health.rollout_ai_unavailable'), $rc['ai_unavailable'].' ('.$pct($rollout['rates']['ai_unavailable_rate']).')') !!}
                {!! $row(__('panel.aicad_health.rollout_data_unavailable'), $rc['data_unavailable_requests'].' ('.$pct($rollout['rates']['data_unavailable_rate']).')') !!}
            </dl>
            <dl class="space-y-1 text-sm">
                {!! $row(__('panel.aicad_health.rollout_regen_reasons'), 'contradiction '.$rc['regeneration_contradiction'].' · coverage '.$rc['regeneration_coverage'].' · empty '.$rc['regeneration_empty']) !!}
                {!! $row(__('panel.aicad_health.rollout_withheld_rate'), $pct($rollout['rates']['withheld_rate'])) !!}
            </dl>
        </div>
    </x-filament::section>

    {{-- Readiness (no network probes on render; php artisan ask:readiness --live for those). --}}
    @php($checks = app(\App\Services\Ai\AicadReadiness::class)->checks(false))
    <x-filament::section>
        <x-slot name="heading">{{ __('panel.aicad_health.readiness') }}: {{ \App\Services\Ai\AicadReadiness::verdict($checks) }}</x-slot>
        <dl class="space-y-1 text-sm">
            @foreach ($checks as $c)
                {!! $row($c['check'], strtoupper($c['status']).' — '.$c['detail'], $c['status'] === 'fail') !!}
            @endforeach
        </dl>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('panel.aicad_health.data_warnings') }} ({{ count($warnings) }})</x-slot>
        <p class="mb-2 text-xs text-gray-500">{{ __('panel.aicad_health.data_warnings_hint') }}</p>
        @forelse ($warnings as $w)
            <div class="flex gap-2 py-1 text-sm">
                <x-filament::badge color="warning">{{ $w['area'] }}</x-filament::badge>
                <span>{{ $w['message'] }}@if ($w['affects'] ?? null) <span class="text-xs text-gray-500">→ {{ implode(', ', $w['affects']) }}</span>@endif</span>
            </div>
        @empty
            <p class="text-sm text-gray-500">—</p>
        @endforelse
    </x-filament::section>
    {{-- Canonical-data coverage per capability (counts only), and the records staff should fill next. --}}
    @php($coverage = app(\App\Services\Ai\AicadCoverage::class))
    @php($matrix = $coverage->matrix())
    @php($summary = $coverage->summary($matrix))
    @php($editUrl = function (?string $resource, ?string $id = null): ?string {
        if ($resource === null) {
            return null;
        }
        try {
            return $id === null ? $resource::getUrl('index') : $resource::getUrl('edit', ['record' => $id]);
        } catch (\Throwable) {
            return null;
        }
    })
    <x-filament::section>
        <x-slot name="heading">{{ __('panel.aicad_health.checklist') }}</x-slot>
        <p class="mb-2 text-xs text-gray-500">{{ __('panel.aicad_health.checklist_hint') }}</p>
        <table class="w-full text-left text-sm">
            <tbody>
                @foreach ($coverage->checklist($matrix) as $item)
                    <tr class="border-t border-gray-100 align-top dark:border-white/5">
                        <td class="py-1 pe-3"><x-filament::badge :color="match ($item['priority']) { 'A' => 'danger', 'B' => 'warning', default => 'gray' }">{{ $item['priority'] }}</x-filament::badge></td>
                        <td class="py-1 pe-3">
                            @php($url = $item['edit'] ? $editUrl($item['edit']['resource']) : null)
                            @if ($url)<a href="{{ $url }}" class="text-primary-600 hover:underline">{{ $item['item'] }}</a>@else{{ $item['item'] }}@endif
                            @if ($item['edit'] && ! $url)<span class="block text-xs text-gray-500">{{ $item['edit']['where'] }}</span>@endif
                        </td>
                        <td class="py-1 pe-3 whitespace-nowrap">{{ $item['total'] === null ? '—' : $item['complete'].' / '.$item['total'] }}@if (($item['missing'] ?? 0) > 0)<span class="block text-xs text-danger-600">{{ __('panel.aicad_health.coverage_missing', ['n' => $item['missing']]) }}</span>@endif</td>
                        <td class="py-1 text-xs text-gray-600 dark:text-gray-300">{{ implode(' · ', $item['affects']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('panel.aicad_health.coverage') }} — {{ collect($coverage->metrics($matrix))->filter()->map(fn ($n, $k) => "$k $n")->implode(' · ') }}</x-slot>
        <p class="mb-2 text-xs text-gray-500">{{ __('panel.aicad_health.coverage_hint') }}</p>
        <p class="mb-2 text-sm">{{ __('panel.aicad_health.coverage_summary', ['implemented' => $summary['implemented'], 'capabilities' => $summary['capabilities'], 'complete' => $summary['data_complete'], 'entities' => $summary['data_entities']]) }}</p>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="text-xs text-gray-500">
                    <tr>
                        <th class="py-1 pe-3">{{ __('panel.aicad_health.coverage_capability') }}</th>
                        <th class="py-1 pe-3">{{ __('panel.aicad_health.coverage_status') }}</th>
                        <th class="py-1 pe-3">{{ __('panel.aicad_health.coverage_complete') }}</th>
                        <th class="py-1 pe-3">{{ __('panel.aicad_health.coverage_gap') }}</th>
                        <th class="py-1 pe-3">{{ __('panel.aicad_health.coverage_affects') }}</th>
                        <th class="py-1">{{ __('panel.aicad_health.coverage_edit') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($matrix as $c)
                        <tr class="border-t border-gray-100 align-top dark:border-white/5">
                            <td class="py-1 pe-3 whitespace-nowrap">{{ $c['priority'] }} {{ $c['capability'] }}@unless ($c['implemented'])<span class="block text-xs text-danger-600">{{ __('panel.aicad_health.coverage_no_code') }}</span>@endunless</td>
                            <td class="py-1 pe-3"><x-filament::badge :color="match ($c['status']) { 'SUPPORTED' => 'success', 'PARTIALLY_SUPPORTED', 'STALE' => 'warning', default => 'danger' }">{{ $c['status'] }}</x-filament::badge></td>
                            <td class="py-1 pe-3 whitespace-nowrap">{{ $c['entities'] === null ? '—' : $c['complete'].' / '.$c['entities'] }}@if (($c['missing'] ?? 0) > 0)<span class="block text-xs text-gray-500">{{ __('panel.aicad_health.coverage_missing', ['n' => $c['missing']]) }}</span>@endif</td>
                            <td class="py-1 pe-3 text-xs">{{ $c['gap'] ?? '—' }}<span class="block text-gray-500">{{ $c['detail'] }}</span></td>
                            <td class="py-1 pe-3 text-xs text-gray-600 dark:text-gray-300">{{ implode(' · ', $c['affects']) }}</td>
                            <td class="py-1 text-xs">
                                @if ($c['edit'])
                                    @php($url = $editUrl($c['edit']['resource']))
                                    @if ($url)<a href="{{ $url }}" class="text-primary-600 hover:underline">{{ $c['edit']['where'] }}</a>@else{{ $c['edit']['where'] }}@endif
                                @else
                                    —
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>

    @php($gaps = collect($coverage->gaps())->groupBy('type'))
    <x-filament::section>
        <x-slot name="heading">{{ __('panel.aicad_health.gaps') }} ({{ $gaps->flatten(1)->count() }})</x-slot>
        <p class="mb-2 text-xs text-gray-500">{{ __('panel.aicad_health.gaps_hint') }}</p>
        @forelse ($gaps as $type => $records)
            <details class="border-t border-gray-100 py-1 dark:border-white/5" @if ($loop->first) open @endif>
                <summary class="cursor-pointer text-sm font-medium">{{ __('panel.aicad_health.gap_types.'.$type) }} — {{ $records->count() }}</summary>
                <ul class="mt-1 space-y-1 text-sm">
                    @foreach ($records as $g)
                        @php($url = $editUrl($g['resource'], $g['id']))
                        <li class="flex flex-wrap gap-x-2">
                            @if ($url)<a href="{{ $url }}" class="text-primary-600 hover:underline">{{ $g['entity'] }}</a>@else<span>{{ $g['entity'] }}</span>@endif
                            <span class="text-danger-600">{{ __('panel.aicad_health.gap_missing') }}: {{ implode(', ', $g['missing']) }}</span>
                            <span class="text-xs text-gray-500">→ {{ implode(', ', $g['affects']) }}@unless ($url) · {{ $g['where'] }}@endunless</span>
                        </li>
                    @endforeach
                </ul>
            </details>
        @empty
            <p class="text-sm text-gray-500">—</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
