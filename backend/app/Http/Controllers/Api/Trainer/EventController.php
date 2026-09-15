<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Events\CampusDataChanged;
use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Api\Concerns\ModeratesContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertTrainerEventRequest;
use App\Models\Event;
use App\Models\EventJoin;
use App\Models\Place;
use App\Models\StaffProfile;
use App\Services\ActivityLogger;
use App\Services\AuditLogger;
use App\Services\EventAttendance;
use App\Services\PlaceConflictChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// A department head publishing events for their OWN department only — see
// Trainer Panel. Every write is scoped to `responsible_staff_id` resolved
// by the `department-head` middleware (never client input, so a trainer
// can't touch another department's event by id-guessing), and starts
// `published` immediately rather than going through the student
// `pending_review` queue — a real department head submitting through
// their own panel already *is* the accountable party a review would be
// checking for. Every write is still logged to the Activity Log for
// after-the-fact auditability.
class EventController extends Controller
{
    use ApiResponds, ModeratesContent;

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
            'workflowStatus' => $e->workflow_status,
            'description' => $e->description,
            'responsibleStaffId' => $e->responsible_staff_id,
        ];
    }

    private function staffOf(Request $request): StaffProfile
    {
        return $request->attributes->get('departmentHeadStaff');
    }

    public function index(Request $request): JsonResponse
    {
        $staff = $this->staffOf($request);
        $events = Event::where('responsible_staff_id', $staff->id)
            ->orderByDesc('event_date')->orderByDesc('id')->get();

        return $this->ok($events->map(fn ($e) => $this->eventToJson($e)));
    }

    public function upsert(UpsertTrainerEventRequest $request): JsonResponse
    {
        $staff = $this->staffOf($request);
        $id = $request->input('id');

        if ($blocked = $this->moderationBlock(
            $this->currentUser(),
            $this->moderationText($request->safe()->only(['title', 'description'])),
            'event',
            'trainer.event.upsert',
        )) {
            return $blocked;
        }

        // A supplied id must already belong to this trainer's own
        // department — 404 rather than 403 so existence of another
        // department's event isn't confirmed/denied by the response.
        if ($id !== null && ! Event::where('id', $id)->where('responsible_staff_id', $staff->id)->exists()) {
            return $this->fail(404, 'EVENT_NOT_FOUND', 'Event not found.');
        }

        $placeId = $request->input('placeId');
        if (! Place::where('id', $placeId)->exists()) {
            return $this->fail(400, 'INVALID_PLACE', 'placeId must reference a real, admin-defined place.');
        }

        $eventDate = $request->input('eventDate');
        $time = $request->input('time', '');
        if ($conflict = PlaceConflictChecker::find($placeId, $eventDate, $time, $id)) {
            return $this->fail(409, 'PLACE_UNAVAILABLE', "Bu mekân o tarihte ve saatte dolu: \"{$conflict->title}\".");
        }

        $place = Place::find($placeId);
        $isNew = $id === null;
        $eventId = $id ?? $this->newId('event');

        $attrs = [
            'title' => $request->input('title'),
            'time' => $time,
            'event_date' => $eventDate,
            'place_name' => $place->name,
            'place_id' => $placeId,
            'category' => $request->input('category', 'Etkinlik'),
            'draft' => false,
            'workflow_status' => 'published',
            'audience' => 'Tümü',
            'organizer' => $staff->name,
            'description' => $request->input('description', ''),
            'responsible_staff_id' => $staff->id,
        ];
        if ($isNew) {
            $attrs['attendees'] = 0;
            $attrs['xp'] = 20;
        }

        $event = Event::updateOrCreate(['id' => $eventId], $attrs);

        ActivityLogger::log($request->user()->id, 'eventJoin',
            $isNew ? "Bölüm etkinliği yayınladı: {$event->title}" : "Bölüm etkinliğini güncelledi: {$event->title}",
            $staff->department ?? $staff->name);
        AuditLogger::logAsCurrentUser($isNew ? 'create' : 'update', 'trainer_event', $event->title);
        $this->announceCampusEvent($isNew ? 'created' : 'updated', $event->id);

        return $this->ok($this->eventToJson($event), $isNew ? 201 : 200);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $staff = $this->staffOf($request);
        $event = Event::where('id', $id)->where('responsible_staff_id', $staff->id)->first();
        if (! $event) {
            return $this->fail(404, 'EVENT_NOT_FOUND', 'Event not found.');
        }

        AuditLogger::logAsCurrentUser('delete', 'trainer_event', $event->title);
        $event->delete();
        $this->announceCampusEvent('deleted', $id);

        return $this->ok(['deleted' => true]);
    }

    private function announceCampusEvent(string $action, string $id): void
    {
        try {
            // Reverb is an acceleration path. The event row has already been
            // committed, and a broadcaster outage must not make publishing
            // fail or leave the trainer uncertain about the result.
            broadcast(new CampusDataChanged(['events'], $action, $id));
        } catch (\Throwable) {
            // REST refresh remains available when broadcasting is offline.
        }
    }

    // Real attendance roster for one of this trainer's own events — same
    // shape/rules as Admin\EventController::participants/approveParticipant,
    // scoped so a trainer can only ever see/approve joins on an event whose
    // responsible_staff_id is their own.
    public function participants(Request $request, string $eventId): JsonResponse
    {
        $staff = $this->staffOf($request);
        $event = Event::where('id', $eventId)->where('responsible_staff_id', $staff->id)->first();
        if (! $event) {
            return $this->fail(404, 'EVENT_NOT_FOUND', 'Event not found.');
        }

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
        $staff = $this->staffOf($request);
        $event = Event::where('id', $eventId)->where('responsible_staff_id', $staff->id)->first();
        if (! $event) {
            return $this->fail(404, 'EVENT_NOT_FOUND', 'Event not found.');
        }

        $join = EventJoin::where('id', $joinId)->where('event_id', $eventId)->first();
        if (! $join) {
            return $this->fail(404, 'JOIN_NOT_FOUND', 'Participation record not found.');
        }
        if (! $join->form_submitted_at) {
            return $this->fail(400, 'FORM_NOT_SUBMITTED', 'Öğrenci katılım formunu henüz doldurmadı.');
        }

        EventAttendance::approve($join, $event, $staff->name);
        AuditLogger::logAsCurrentUser('approve_attendance', 'event_join', "{$join->user?->name} → {$event->title}");

        return $this->ok(['approved' => true]);
    }
}
