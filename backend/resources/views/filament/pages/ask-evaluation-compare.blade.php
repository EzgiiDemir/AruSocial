{{-- Two evaluation runs side by side: factual test results only, no composite score. --}}
@php($fmt = fn ($v, $kind) => $v === null ? '—' : (in_array($kind, ['rate', 'rate_lower'], true) ? round($v * 100, 1).'%' : $v))
<x-filament-panels::page>
    {{ $this->form }}

    @if ($diff)
        <x-filament::section>
            <div class="flex flex-wrap gap-2">
                <x-filament::badge color="danger">{{ __('panel.aicad_runs.newly_failing') }}: {{ count($diff['newly_failing']) }}</x-filament::badge>
                <x-filament::badge color="success">{{ __('panel.aicad_runs.newly_passing') }}: {{ count($diff['newly_passing']) }}</x-filament::badge>
                <x-filament::badge color="warning">{{ __('panel.aicad_runs.unchanged_failing') }}: {{ count($diff['unchanged_failing']) }}</x-filament::badge>
                <x-filament::badge color="gray">{{ __('panel.aicad_runs.unchanged_passing') }}: {{ $diff['unchanged_passing'] }}</x-filament::badge>
                @if ($diff['only_in_baseline'] || $diff['only_in_current'])
                    <x-filament::badge color="gray">{{ __('panel.aicad_runs.not_comparable', ['a' => $diff['only_in_baseline'], 'b' => $diff['only_in_current']]) }}</x-filament::badge>
                @endif
            </div>
        </x-filament::section>

        @foreach (['newly_failing' => 'danger', 'newly_passing' => 'success', 'unchanged_failing' => 'warning'] as $key => $color)
            @if ($diff[$key])
                <x-filament::section collapsible>
                    <x-slot name="heading">{{ __('panel.aicad_runs.'.$key) }} ({{ count($diff[$key]) }})</x-slot>
                    @foreach ($diff[$key] as $row)
                        <div class="flex flex-wrap items-baseline gap-2 border-t border-gray-200 py-1 text-sm first:border-t-0 dark:border-white/10">
                            <span class="font-medium">{{ $row['name'] }}</span>
                            <span class="text-xs text-gray-500">{{ \Illuminate\Support\Str::limit($row['question'], 60) }}</span>
                            <x-filament::badge :color="$color">{{ str_replace('_', ' ', $row['stage_before'] ?? 'pass') }} → {{ str_replace('_', ' ', $row['stage_after'] ?? 'pass') }}</x-filament::badge>
                        </div>
                    @endforeach
                </x-filament::section>
            @endif
        @endforeach

        <x-filament::section>
            <x-slot name="heading">{{ __('panel.aicad_runs.metrics') }}</x-slot>
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase text-gray-500"><tr><th class="py-1">metric</th><th>{{ __('panel.aicad_runs.baseline') }}</th><th>{{ __('panel.aicad_runs.current') }}</th></tr></thead>
                <tbody>
                    @foreach ($diff['metrics'] as $row)
                        {{-- Higher is better for rates; lower for rate_lower, latency and dropped/irrelevant counts. --}}
                        @php($worse = $row['before'] !== null && $row['after'] !== null && ($row['kind'] === 'rate' ? $row['after'] < $row['before'] : ($row['metric'] !== 'prompt included' && $row['after'] > $row['before'])))
                        <tr class="border-t border-gray-200 dark:border-white/10">
                            <td class="py-1">{{ $row['metric'] }}</td>
                            <td>{{ $fmt($row['before'], $row['kind']) }}</td>
                            <td class="{{ $worse ? 'text-danger-600' : '' }}">{{ $fmt($row['after'], $row['kind']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="mt-3 text-xs text-gray-500">
                @foreach ($diff['fingerprints'] as $field => [$before, $after])
                    <span class="mr-3 {{ $before !== $after ? 'text-warning-600' : '' }}">{{ $field }}: {{ \Illuminate\Support\Str::limit((string) $before, 12, '') ?: '—' }} → {{ \Illuminate\Support\Str::limit((string) $after, 12, '') ?: '—' }}</span>
                @endforeach
            </div>
        </x-filament::section>
    @else
        <p class="text-sm text-gray-500">{{ __('panel.aicad_runs.pick_two') }}</p>
    @endif
</x-filament-panels::page>
