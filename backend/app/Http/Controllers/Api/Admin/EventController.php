<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\EventJoin;
use App\Models\EventParticipationType;
use App\Services\ActivityLogger;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    use ApiResponds;

    private function eventToJson(Event $e): array
    {
        return [
            'id' => $e->id,
            'title' => $e->title,
            'time' => $e->time,
            'eventDate' => $e->event_date?->toDateString(),
            'placeName' => $e->place_name,
            'placeId' => $e->place_id,
            'category' => $e->category,
            'attendees' => $e->attendees,
            'xp' => $e->xp,
            'draft' => $e->draft,
            'publishAt' => $e->publish_at?->toIso8601String(),
            'expiresAt' => $e->expires_at?->toIso8601String(),
            'audience' => $e->audience,
            'organizer' => $e->organizer,
            'organizerEmail' => $e->organizer_email,
            'description' => $e->description,
            'workflowStatus' => $e->workflow_status,
            'reviewNote' => $e->review_note,
            'createdByUserId' => $e->created_by_user_id,
            'academicYearId' => $e->academic_year_id,
            'participationTypes' => $e->participationTypes->map(fn ($t) => ['id' => $t->id, 'label' => $t->label]),
        ];
    }

    // Real "boş/dolu" mekân müsaitlik kontrolü (docs/EKSIKLER.md §4) —
    // mirrors EventController::placeConflict(); an active (non-rejected)
    // event already at this place, same date+time, blocks this save.
    private function placeConflict(?string $placeId, ?string $eventDate, string $time, ?string $excludeEventId = null): ?Event
    {
        if (! $placeId || ! $eventDate || $time === '') return null;

        return Event::where('place_id', $placeId)
            ->whereDate('event_date', $eventDate)
            ->where('time', $time)
            ->whereNotIn('workflow_status', ['rejected'])
            ->when($excludeEventId, fn ($q) => $q->where('id', '!=', $excludeEventId))
            ->first();
    }

    public function upsert(Request $request): JsonResponse
    {
        $id = $request->input('id');
        $title = $request->input('title');
        if (! $id || ! $title) {
            return $this->fail(400, 'VALIDATION', 'id and title are required.');
        }

        $placeId = $request->input('placeId');
        $eventDate = $request->input('eventDate');
        $time = $request->input('time', '');
        if ($conflict = $this->placeConflict($placeId, $eventDate, $time, $id)) {
            return $this->fail(409, 'PLACE_UNAVAILABLE', "Bu mekân o tarihte ve saatte dolu: \"{$conflict->title}\".");
        }

        $isNew = ! Event::where('id', $id)->exists();
        $event = Event::updateOrCreate(
            ['id' => $id],
            [
                'title' => $title,
                'time' => $time,
                'event_date' => $eventDate,
                'place_name' => $request->input('placeName', ''),
                'place_id' => $placeId,
                'category' => $request->input('category', ''),
                'attendees' => (int) $request->input('attendees', 0),
                'xp' => (int) $request->input('xp', 0),
                'draft' => (bool) $request->input('draft', false),
                'workflow_status' => $request->input('draft', false) ? 'draft' : 'published',
                'publish_at' => $request->input('publishAt'),
                'expires_at' => $request->input('expiresAt'),
                'audience' => $request->input('audience', 'Tümü'),
                'organizer' => $request->input('organizer', ''),
                'organizer_email' => $request->input('organizerEmail'),
                'description' => $request->input('description', ''),
                'academic_year_id' => $request->input('academicYearId'),
            ]
        );
        AuditLogger::log($request->input('actorName', 'admin'), $isNew ? 'create' : 'update', 'event', $title);

        return $this->ok($this->eventToJson($event->fresh('participationTypes')));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $event = Event::find($id);
        if ($event) {
            AuditLogger::log($request->input('actorName', 'admin'), 'delete', 'event', $event->title);
            $event->delete();
        }

        return $this->ok(['deleted' => true]);
    }

    // Real "Kendi Aktiviteni Oluştur" review queue (docs/EKSIKLER.md §5).
    public function pendingActivities(): JsonResponse
    {
        $events = Event::with('participationTypes')
            ->where('workflow_status', 'pending_review')
            ->orderByDesc('id')->get();

        return $this->ok($events->map(fn ($e) => $this->eventToJson($e)));
    }

    public function approveActivity(Request $request, string $id): JsonResponse
    {
        $event = Event::find($id);
        if (! $event) return $this->fail(404, 'EVENT_NOT_FOUND', 'Event not found.');

        // 'published', not 'approved' — the public index() filter requires
        // workflow_status='published' to show an event at all, so anything
        // else here would approve an activity that then never appears.
        $event->update(['workflow_status' => 'published', 'draft' => false, 'review_note' => null]);
        if ($event->created_by_user_id) {
            ActivityLogger::log($event->created_by_user_id, 'eventJoin', "Aktiviten onaylandı: {$event->title}", 'Yayında');
        }
        AuditLogger::log($request->input('actorName', 'admin'), 'approve', 'event', $event->title);

        return $this->ok($this->eventToJson($event->fresh('participationTypes')));
    }

    public function rejectActivity(Request $request, string $id): JsonResponse
    {
        $event = Event::find($id);
        if (! $event) return $this->fail(404, 'EVENT_NOT_FOUND', 'Event not found.');

        $note = $request->input('reviewNote', '');
        $event->update(['workflow_status' => 'rejected', 'review_note' => $note]);
        if ($event->created_by_user_id) {
            ActivityLogger::log($event->created_by_user_id, 'eventJoin', "Aktiviten reddedildi: {$event->title}", $note ?: 'Sebep belirtilmedi');
        }
        AuditLogger::log($request->input('actorName', 'admin'), 'reject', 'event', $event->title);

        return $this->ok($this->eventToJson($event->fresh('participationTypes')));
    }

    // Admin participation-type management (docs/EKSIKLER.md §5) —
    // "Katılımcı / Gönüllü / Organizasyon / Görevli" per event, editable.
    public function upsertParticipationType(Request $request, string $eventId): JsonResponse
    {
        $event = Event::find($eventId);
        if (! $event) return $this->fail(404, 'EVENT_NOT_FOUND', 'Event not found.');
        $label = $request->input('label');
        if (! $label) return $this->fail(400, 'VALIDATION', 'label is required.');

        $id = $request->input('id') ?: 'ptype-'.\Illuminate\Support\Str::uuid();
        $type = EventParticipationType::updateOrCreate(
            ['id' => $id],
            ['event_id' => $eventId, 'label' => $label, 'sort_order' => (int) $request->input('sortOrder', 0)]
        );

        return $this->ok(['id' => $type->id, 'label' => $type->label]);
    }

    public function destroyParticipationType(string $eventId, string $typeId): JsonResponse
    {
        EventParticipationType::where('id', $typeId)->where('event_id', $eventId)->delete();

        return $this->ok(['deleted' => true]);
    }

    // Real teacher/club-manager attendance roster (docs/EKSIKLER.md §5):
    // who actually joined this event, with their chosen participation
    // type, and whether an admin has actually approved their attendance
    // yet — joining alone doesn't mean "attending", someone has to say so.
    public function participants(string $eventId): JsonResponse
    {
        $event = Event::find($eventId);
        if (! $event) return $this->fail(404, 'EVENT_NOT_FOUND', 'Event not found.');

        $joins = EventJoin::with(['user', 'participationType'])
            ->where('event_id', $eventId)
            ->orderByDesc('joined_at')
            ->get();

        return $this->ok($joins->map(fn (EventJoin $j) => [
            'id' => $j->id,
            'userId' => $j->user_id,
            'studentName' => $j->user?->name,
            'studentEmail' => $j->user?->email,
            'participationTypeLabel' => $j->participationType?->label,
            'joinedAt' => $j->joined_at?->toIso8601String(),
            'formSubmittedAt' => $j->form_submitted_at?->toIso8601String(),
            'approvedAt' => $j->approved_at?->toIso8601String(),
            'approvedBy' => $j->approved_by,
        ]));
    }

    public function approveParticipant(Request $request, string $eventId, string $joinId): JsonResponse
    {
        $join = EventJoin::where('id', $joinId)->where('event_id', $eventId)->first();
        if (! $join) return $this->fail(404, 'JOIN_NOT_FOUND', 'Participation record not found.');
        // The real gate (docs/EKSIKLER.md §5): a student who hasn't
        // completed the katılım formu isn't reviewable yet — approving
        // attendance before the form is in is not allowed, not just
        // discouraged in the UI.
        if (! $join->form_submitted_at) {
            return $this->fail(400, 'FORM_NOT_SUBMITTED', 'Öğrenci katılım formunu henüz doldurmadı.');
        }

        $actorName = $request->input('actorName', 'admin');
        $join->update(['approved_at' => now(), 'approved_by' => $actorName]);

        $event = Event::find($eventId);
        if ($join->user_id) {
            ActivityLogger::log(
                $join->user_id,
                'eventJoin',
                "Katılımın onaylandı: {$event?->title}",
                'Yoklama alındı',
            );
        }
        AuditLogger::log($actorName, 'approve_attendance', 'event_join', "{$join->user?->name} → {$event?->title}");

        return $this->ok(['approved' => true]);
    }
}
