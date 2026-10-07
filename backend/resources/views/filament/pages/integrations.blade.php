{{--
    Integrations. One card per provider.

    Deliberately cards rather than a Filament table: every row carries four
    timestamps plus two actions, which a table compresses into unreadable
    columns on a laptop. Nothing here renders a credential — the only
    secret-derived value is `secretMasked`, which the registry has already
    reduced to a masked tail.
--}}
<x-filament-panels::page>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($this->rows as $row)
            @php($badge = $this->statusBadge($row['status']))
            <x-filament::section class="h-full">
                <x-slot name="heading">
                    <div class="flex items-start justify-between gap-3">
                        <span>{{ $row['name'] }}</span>
                        <x-filament::badge :color="$badge['color']">{{ $badge['label'] }}</x-filament::badge>
                    </div>
                </x-slot>

                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ $row['description'] }}
                </p>

                <dl class="mt-4 space-y-1.5 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('panel.integrations.last_test') }}</dt>
                        <dd class="text-gray-900 dark:text-gray-100">{{ $this->human($row['lastTestAt']) }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('panel.integrations.last_success') }}</dt>
                        <dd class="text-gray-900 dark:text-gray-100">{{ $this->human($row['lastSuccessAt']) }}</dd>
                    </div>
                    @if ($row['secretMasked'])
                        <div class="flex justify-between gap-3">
                            <dt class="text-gray-500 dark:text-gray-400">{{ __('panel.integrations.secret') }}</dt>
                            <dd class="font-mono text-gray-900 dark:text-gray-100">{{ $row['secretMasked'] }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between gap-3">
                        <dt class="text-gray-500 dark:text-gray-400">{{ __('panel.integrations.managed_via') }}</dt>
                        <dd class="text-gray-900 dark:text-gray-100">
                            {{ $row['managedVia'] === 'admin'
                                ? __('panel.integrations.managed_admin')
                                : __('panel.integrations.managed_env') }}
                        </dd>
                    </div>
                </dl>

                @if ($row['lastError'])
                    {{-- Operator-facing text only; the technical detail is in the server log. --}}
                    <p class="mt-3 rounded-md bg-danger-50 px-3 py-2 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
                        {{ $row['lastError'] }}
                    </p>
                @endif

                @if (! $row['configured'] && count($row['envKeys']))
                    {{-- Names of the variables to set. Never their values. --}}
                    <div class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('panel.integrations.needs_env') }}
                        <span class="font-mono">{{ implode(', ', $row['envKeys']) }}</span>
                    </div>
                @endif

                @if (! $row['remotelyTestable'])
                    <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('panel.integrations.no_live_test') }}
                    </p>
                @endif

                @if ($this->canManage())
                    <div class="mt-4 flex flex-wrap gap-2">
                        <x-filament::button
                            size="sm"
                            color="gray"
                            wire:click="test('{{ $row['key'] }}')"
                            wire:loading.attr="disabled"
                            wire:target="test('{{ $row['key'] }}')"
                        >
                            {{ __('panel.integrations.test') }}
                        </x-filament::button>

                        @if ($row['enabled'])
                            <x-filament::button
                                size="sm"
                                color="danger"
                                wire:click="toggle('{{ $row['key'] }}', false)"
                                wire:loading.attr="disabled"
                            >
                                {{ __('panel.integrations.disable') }}
                            </x-filament::button>
                        @else
                            <x-filament::button
                                size="sm"
                                color="success"
                                wire:click="toggle('{{ $row['key'] }}', true)"
                                wire:loading.attr="disabled"
                            >
                                {{ __('panel.integrations.enable') }}
                            </x-filament::button>
                        @endif

                        @if ($row['managedVia'] === 'admin')
                            {{-- Configuration lives in the existing settings screen;
                                 this page never edits a credential itself. --}}
                            <x-filament::button
                                size="sm"
                                color="gray"
                                tag="a"
                                href="{{ url('/admin') }}"
                            >
                                {{ __('panel.integrations.configure') }}
                            </x-filament::button>
                        @endif
                    </div>
                @else
                    <p class="mt-4 text-xs text-gray-500 dark:text-gray-400">
                        {{ __('panel.integrations.read_only') }}
                    </p>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
