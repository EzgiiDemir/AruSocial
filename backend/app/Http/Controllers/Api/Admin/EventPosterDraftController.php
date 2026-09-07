<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\DraftEventFromPosterRequest;
use App\Models\Event;
use App\Models\MediaItem;
use App\Services\AuditLogger;
use App\Services\EventPosterDraftService;
use Illuminate\Http\JsonResponse;

class EventPosterDraftController extends Controller
{
    use ApiResponds;

    // Poster image → draft event only. Never publishes. Requires
    // events.manage. Without GROQ_API_KEY returns 501 AI_NOT_CONFIGURED.
    public function store(DraftEventFromPosterRequest $request): JsonResponse
    {
        if (! EventPosterDraftService::isConfigured()) {
            return $this->fail(
                501,
                'AI_NOT_CONFIGURED',
                'GROQ_API_KEY is not set — poster draft extraction is unavailable.'
            );
        }

        $file = $request->file('file');
        $mime = $file->getMimeType();
        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            return $this->fail(400, 'UNSUPPORTED_FILE_TYPE', 'Poster must be an image.');
        }
        if ($file->getSize() > 8 * 1024 * 1024) {
            return $this->fail(400, 'FILE_TOO_LARGE', 'File exceeds the 8MB limit.');
        }

        $bytes = file_get_contents($file->getRealPath());
        $extracted = EventPosterDraftService::extractFromImage($bytes, $mime);
        if ($extracted === null) {
            return $this->fail(502, 'AI_UPSTREAM_ERROR', 'Could not extract event fields from the poster.');
        }

        $path = $file->store('media', MediaItem::disk());
        $media = MediaItem::create([
            'id' => $this->newId('media'),
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'mime_type' => $mime,
            'size_bytes' => $file->getSize(),
            'uploaded_at' => now(),
            'uploaded_by' => $this->currentUser()->name,
            'used_in' => [],
            'moderation_status' => 'approved',
        ]);

        $eventId = $this->newId('event');
        $title = $extracted['title'] ?? 'Untitled poster draft';
        $event = Event::create([
            'id' => $eventId,
            'title' => $title,
            'time' => $extracted['time'] ?? '',
            'event_date' => $extracted['eventDate'],
            'place_name' => $extracted['placeName'] ?? '',
            'category' => $extracted['category'] ?? 'Etkinlik',
            'attendees' => 0,
            'xp' => 30,
            'draft' => true,
            'audience' => 'Tümü',
            'organizer' => $extracted['organizer'] ?? '',
            'description' => $extracted['description'] ?? '',
            'workflow_status' => 'draft',
            'ai_draft' => true,
            'ai_source_media_id' => $media->id,
            'created_by_user_id' => $this->currentUser()->id,
        ]);

        $media->used_in = ['event-poster:'.$event->id];
        $media->save();

        AuditLogger::logAsCurrentUser('create', 'event_ai_draft', $event->title);

        return $this->ok([
            'event' => [
                'id' => $event->id,
                'title' => $event->title,
                'time' => $event->time,
                'eventDate' => $event->event_date?->toDateString(),
                'placeName' => $event->place_name,
                'category' => $event->category,
                'attendees' => $event->attendees,
                'xp' => $event->xp,
                'draft' => true,
                'workflowStatus' => 'draft',
                'organizer' => $event->organizer,
                'description' => $event->description,
                'aiDraft' => true,
                'aiSourceMediaId' => $media->id,
            ],
            'extracted' => $extracted,
            'incomplete' => $extracted['incomplete'],
            'mediaId' => $media->id,
        ], 201);
    }
}
