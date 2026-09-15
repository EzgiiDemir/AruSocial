{{-- What the file actually is, and what would break if it went. --}}
<div class="space-y-4">
    @if (str_starts_with((string) $item->mime_type, 'image/'))
        <img src="{{ $url }}" alt="{{ $item->file_name }}"
             class="max-h-96 w-full rounded-lg object-contain bg-gray-50 dark:bg-gray-900">
    @else
        <div class="rounded-lg bg-gray-50 p-6 text-center text-sm dark:bg-gray-900">
            {{ $item->mime_type ?: __('panel.media.unknown_type') }}
        </div>
    @endif

    <dl class="grid grid-cols-2 gap-3 text-sm">
        <div>
            <dt class="text-gray-500">{{ __('panel.media.uploaded_by') }}</dt>
            <dd>{{ $item->uploaded_by }}</dd>
        </div>
        <div>
            <dt class="text-gray-500">{{ __('panel.media.uploaded_at') }}</dt>
            <dd>{{ optional($item->uploaded_at)->format('d.m.Y H:i') }}</dd>
        </div>
        <div>
            <dt class="text-gray-500">{{ __('panel.media.size') }}</dt>
            <dd>{{ \App\Services\Media\MediaAudit::humanBytes((int) $item->size_bytes) }}</dd>
        </div>
        <div>
            <dt class="text-gray-500">{{ __('panel.media.status') }}</dt>
            <dd>{{ $item->moderation_status }}</dd>
        </div>
    </dl>

    {{-- The part that matters before a delete. --}}
    <div>
        <p class="text-sm font-semibold">{{ __('panel.media.used_in') }}</p>
        @if (filled($references))
            <ul class="mt-1 list-disc pl-5 text-sm">
                @foreach ($references as $reference)
                    <li>{{ $reference }}</li>
                @endforeach
            </ul>
        @else
            <p class="mt-1 text-sm text-gray-500">{{ __('panel.media.unused') }}</p>
        @endif
    </div>
</div>
