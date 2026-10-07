{{--
    One crawled page, as AICAD sees it: metadata, the extracted text that is
    ranked and quoted, and each passage with its embedding state. Public page
    text only — the crawler stores nothing else.
--}}
@php($doc = $this->getRecord())
@php($chunks = $this->chunkRows())
@php($text = $this->extractedText())
<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">{{ $doc->title ?: $doc->url }}</x-slot>
        <dl class="grid grid-cols-1 gap-x-6 gap-y-1.5 text-sm md:grid-cols-2">
            @foreach ([
                'url' => $doc->url,
                'content_type' => $doc->content_type,
                'http_status' => $doc->http_status,
                'language' => $doc->language,
                'chars' => number_format((int) $doc->content_length),
                'fetched_at' => $doc->fetched_at?->toDayDateTimeString(),
                'last_modified' => $doc->last_modified_at?->toDayDateTimeString(),
                'authority' => $doc->authority,
                'stale' => $doc->is_stale ? __('panel.knowledge_documents.yes') : __('panel.knowledge_documents.no'),
                'fail_count' => $doc->fail_count,
                'content_hash' => $doc->content_hash,
            ] as $key => $value)
                <div class="flex justify-between gap-3">
                    <dt class="text-gray-500 dark:text-gray-400">{{ __('panel.knowledge_documents.'.$key) }}</dt>
                    <dd class="break-all text-right text-gray-900 dark:text-gray-100">{{ $value ?? '—' }}</dd>
                </div>
            @endforeach
        </dl>
        @if ($doc->last_error)
            <p class="mt-3 rounded-md bg-danger-50 px-3 py-2 text-sm text-danger-700 dark:bg-danger-400/10 dark:text-danger-400">
                {{ $doc->last_error }}
            </p>
        @endif
        @if (mb_strlen($text) < 300)
            {{-- Thin extraction: navigation-only HTML, an error page, or content built by JavaScript. --}}
            <p class="mt-3 rounded-md bg-warning-50 px-3 py-2 text-sm text-warning-700 dark:bg-warning-400/10 dark:text-warning-400">
                {{ __('panel.knowledge_documents.thin_warning') }}
            </p>
        @endif
    </x-filament::section>

    <x-filament::section collapsible>
        <x-slot name="heading">{{ __('panel.knowledge_documents.extracted') }} ({{ number_format(mb_strlen($text)) }})</x-slot>
        <pre class="max-h-96 overflow-auto whitespace-pre-wrap text-xs text-gray-800 dark:text-gray-200">{{ $text }}</pre>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">{{ __('panel.knowledge_documents.chunks') }} ({{ count($chunks) }})</x-slot>
        @forelse ($chunks as $chunk)
            <div class="border-t border-gray-200 py-2 first:border-t-0 dark:border-white/10">
                <div class="mb-1 flex flex-wrap gap-2 text-xs">
                    <x-filament::badge color="gray">#{{ $chunk['position'] }}</x-filament::badge>
                    <x-filament::badge color="gray">{{ $chunk['chars'] }} {{ __('panel.knowledge_documents.chars') }}</x-filament::badge>
                    @if ($chunk['dimensions'])
                        <x-filament::badge color="success">{{ $chunk['model'] }} · {{ $chunk['dimensions'] }}d</x-filament::badge>
                    @else
                        <x-filament::badge color="danger">{{ __('panel.knowledge_documents.no_embedding') }}</x-filament::badge>
                    @endif
                </div>
                <p class="whitespace-pre-wrap text-sm text-gray-800 dark:text-gray-200">{{ $chunk['text'] }}</p>
            </div>
        @empty
            <p class="text-sm text-gray-500">{{ __('panel.knowledge_documents.no_chunks') }}</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
