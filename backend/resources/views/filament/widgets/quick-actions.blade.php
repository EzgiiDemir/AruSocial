{{-- The few things staff come here to do, on the page they land on. --}}
@php($actions = $this->getActions())

@if (filled($actions))
    <x-filament-widgets::widget>
        <x-filament::section :heading="__('panel.dashboard.quick_actions')" compact>
            <div class="flex flex-wrap gap-3">
                @foreach ($actions as $action)
                    <x-filament::button
                        tag="a"
                        :href="$action['url']"
                        :icon="$action['icon']"
                        color="gray"
                        outlined
                    >
                        {{ __('panel.dashboard.new', ['thing' => $action['label']]) }}
                    </x-filament::button>
                @endforeach
            </div>
        </x-filament::section>
    </x-filament-widgets::widget>
@endif
