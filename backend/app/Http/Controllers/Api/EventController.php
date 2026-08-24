<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreateOwnActivityRequest;
use App\Mail\EventParticipationClubMail;
use App\Mail\EventParticipationFormCompletedMail;
use App\Mail\EventParticipationFormMail;
use App\Models\AcademicYear;
use App\Models\Event;
use App\Models\EventJoin;
use App\Models\EventParticipationType;
use App\Services\ActivityLogger;
use App\Services\EmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\UniqueConstraintViolationException;

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
            'category' => $e->category,
            'attendees' => $e->attendees,
            'xp' => $e->xp,
            // Extra fields the current CampusEventDto doesn't read yet —
            // see docs/EKSIKLER.md.
            'draft' => $e->draft,
            'publishAt' => $e->publish_at?->toIso8601String(),
            'expiresAt' => $e->expires_at?->toIso8601String(),
            'audience' => $e->audience,
            'organizer' => $e->organizer,
            'organizerEmail' => $e->organizer_email,
            'description' => $e->description,
            // Real workflow/participation fields (docs/EKSIKLER.md §5).
            'workflowStatus' => $e->workflow_status,
            'reviewNote' => $e->review_note,
            'placeId' => $e->place_id,
            'academicYearId' => $e->academic_year_id,
            'createdByUserId' => $e->created_by_user_id,
            'participationTypes' => $e->participationTypes->map(fn ($t) => [
                'id' => $t->id,
                'label' => $t->label,
            ]),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = Event::with('participationTypes');
        if ($request->query('includeUnpublished') !== 'true') {
            $query->where('draft', false)->where('workflow_status', 'published');
        }
        if ($academicYearId = $request->query('academicYearId')) {
            $query->where('academic_year_id', $academicYearId);
        }

        return $this->ok($query->get()->map(fn ($e) => $this->eventToJson($e)));
    }

    public function show(string $id): JsonResponse
    {
        $event = Event::with('participationTypes')->find($id);
        if (! $event) {
            return $this->fail(404, 'EVENT_NOT_FOUND', 'Event not found.');
        }

        return $this->ok($this->eventToJson($event));
    }

    // Real katılım popup workflow (docs/EKSIKLER.md §5/§6), sequential and
    // gated: (1) joining records the join and tells the organizer someone
    // wants in, and emails the student the form; (2) the join is NOT
    // actionable for the organizer yet — see submitForm() below, which is
    // the real gate: only once the student actually completes the form
    // does a second, distinct email tell the organizer it's ready to
    // review, and only then can Admin\EventController::approveParticipant()
    // accept it. Attendance approval before form completion is a hard
    // 400, not just a UI suggestion.
    public function join(Request $request, string $id): JsonResponse
    {
        $event = Event::with('participationTypes')->find($id);
        if (! $event) {
            return $this->fail(404, 'EVENT_NOT_FOUND', 'Event not found.');
        }
        $me = $this->currentUser();

        $participationTypeId = $request->input('participationTypeId');
        if ($participationTypeId && ! $event->participationTypes->contains('id', $participationTypeId)) {
            return $this->fail(400, 'INVALID_PARTICIPATION_TYPE', 'That participation type does not belong to this event.');
        }

        $alreadyJoined = EventJoin::where('event_id', $event->id)->where('user_id', $me->id)->exists();
        $emailStatus = ['clubEmailSent' => false, 'formEmailSent' => false];
        $formSubmitted = false;

        if (! $alreadyJoined) {
            try {
                DB::transaction(function () use ($event, $me, $participationTypeId) {
                    EventJoin::create([
                        'id' => $this->newId('join'),
                        'event_id' => $event->id,
                        'user_id' => $me->id,
                        'participation_type_id' => $participationTypeId,
                        'joined_at' => now(),
                    ]);
                    $event->increment('attendees');
                    $me->increment('xp', $event->xp);
                    $me->increment('events');
                    ActivityLogger::log($me->id, 'eventJoin', "Katıldın: {$event->title}", "+{$event->xp} XP");
                });
            } catch (UniqueConstraintViolationException) {
                // Idempotent per user — see unique(event_id, user_id).
            }

            $label = $participationTypeId
                ? $event->participationTypes->firstWhere('id', $participationTypeId)?->label
                : null;

            if ($event->organizer_email) {
                $log = EmailService::send(
                    $event->organizer_email,
                    "Yeni katılım talebi: {$event->title}",
                    'event-participation-club',
                    new EventParticipationClubMail($event, $me, $label)
                );
                $emailStatus['clubEmailSent'] = $log->status === 'sent';
            }
            $formLog = EmailService::send(
                $me->email,
                "Katılım formu: {$event->title}",
                'event-participation-form',
                new EventParticipationFormMail($event, null)
            );
            $emailStatus['formEmailSent'] = $formLog->status === 'sent';
        } else {
            $formSubmitted = (bool) EventJoin::where('event_id', $event->id)
                ->where('user_id', $me->id)
                ->value('form_submitted_at');
        }

        return $this->ok([
            ...$this->eventToJson($event->fresh('participationTypes')),
            'participationStatus' => [
                'joined' => true,
                'alreadyJoined' => $alreadyJoined,
                'formSubmitted' => $formSubmitted,
                ...$emailStatus,
            ],
        ]);
    }

    // The real gate (docs/EKSIKLER.md §5): the student explicitly completes
    // the form in-app (rather than the join popup silently assuming an
    // emailed form got filled out somewhere else). Only this call — never
    // join() itself — makes the participation actionable for the
    // organizer: it fires the "ready to review" email and is what
    // Admin\EventController::approveParticipant() requires before it will
    // approve attendance.
    public function submitForm(string $id): JsonResponse
    {
        $event = Event::find($id);
        if (! $event) {
            return $this->fail(404, 'EVENT_NOT_FOUND', 'Event not found.');
        }
        $me = $this->currentUser();

        $join = EventJoin::where('event_id', $event->id)->where('user_id', $me->id)->first();
        if (! $join) {
            return $this->fail(400, 'NOT_JOINED', 'You must join this event before completing its form.');
        }

        $formCompletedEmailSent = false;
        if (! $join->form_submitted_at) {
            $join->update(['form_submitted_at' => now()]);
            ActivityLogger::log($me->id, 'eventJoin', "Katılım formunu doldurdun: {$event->title}", 'Onay bekleniyor');

            if ($event->organizer_email) {
                $label = $join->participation_type_id
                    ? $event->participationTypes->firstWhere('id', $join->participation_type_id)?->label
                    : null;
                $log = EmailService::send(
                    $event->organizer_email,
                    "Onay bekliyor: {$event->title}",
                    'event-participation-form-completed',
                    new EventParticipationFormCompletedMail($event, $me, $label)
                );
                $formCompletedEmailSent = $log->status === 'sent';
            }
        }

        return $this->ok([
            'formSubmitted' => true,
            'formCompletedEmailSent' => $formCompletedEmailSent,
        ]);
    }

    // Real "boş/dolu" mekân müsaitlik kontrolü (docs/EKSIKLER.md §4):
    // an active (non-rejected) event already at this place, on this same
    // date and time slot, blocks a new one — shared by both the student
    // own-activity flow and Admin\EventController::upsert() so neither
    // path can double-book a place, only one edit apart from a real race.
    private function placeConflict(string $placeId, ?string $eventDate, string $time, ?string $excludeEventId = null): ?Event
    {
        if (! $eventDate || $time === '') return null;

        return Event::where('place_id', $placeId)
            ->whereDate('event_date', $eventDate)
            ->where('time', $time)
            ->whereNotIn('workflow_status', ['rejected'])
            ->when($excludeEventId, fn ($q) => $q->where('id', '!=', $excludeEventId))
            ->first();
    }

    // Real "kendi aktiviteni oluştur" flow (docs/EKSIKLER.md §5): a
    // student submits a draft that starts in pending_review, not
    // published — nothing goes live without an admin approving it.
    public function createOwnActivity(CreateOwnActivityRequest $request): JsonResponse
    {
        $me = $this->currentUser();
        $title = $request->input('title');
        $placeId = $request->input('placeId');
        if (! \App\Models\Place::where('id', $placeId)->exists()) {
            return $this->fail(400, 'INVALID_PLACE', 'placeId must reference a real, admin-defined place.');
        }

        $eventDate = $request->input('eventDate');
        $time = $request->input('time', '');
        if ($conflict = $this->placeConflict($placeId, $eventDate, $time)) {
            return $this->fail(409, 'PLACE_UNAVAILABLE', "Bu mekân o tarihte ve saatte dolu: \"{$conflict->title}\".");
        }

        $place = \App\Models\Place::find($placeId);
        $activeYear = AcademicYear::where('is_active', true)->first();

        $event = Event::create([
            'id' => $this->newId('event'),
            'title' => $title,
            'time' => $time,
            'event_date' => $eventDate,
            'place_name' => $place->name,
            'place_id' => $placeId,
            'category' => $request->input('category', 'Öğrenci Etkinliği'),
            'attendees' => 0,
            'xp' => 20,
            'draft' => true,
            'workflow_status' => 'pending_review',
            'audience' => 'Tümü',
            'organizer' => $me->name,
            'description' => $request->input('description', ''),
            'created_by_user_id' => $me->id,
            'academic_year_id' => $activeYear?->id,
        ]);
        ActivityLogger::log($me->id, 'eventJoin', "Aktivite önerdin: {$title}", 'İnceleme bekliyor');

        return $this->ok($this->eventToJson($event->fresh('participationTypes')), 201);
    }

    // Everything a student has proposed, with real status.
    public function myActivities(): JsonResponse
    {
        $me = $this->currentUser();
        $events = Event::with('participationTypes')->where('created_by_user_id', $me->id)
            ->orderByDesc('id')->get();

        return $this->ok($events->map(fn ($e) => $this->eventToJson($e)));
    }
}
