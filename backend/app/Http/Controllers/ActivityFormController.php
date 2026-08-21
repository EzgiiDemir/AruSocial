<?php

namespace App\Http\Controllers;

use App\Mail\ActivityFormSubmittedMail;
use App\Mail\ActivityPendingApprovalMail;
use App\Models\Event;
use App\Services\ActivityLogger;
use App\Services\AuditLogger;
use App\Services\AcademicRoutingService;
use App\Services\EmailService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;

// The real, single-use web form behind the signed link
// ActivityFormRequestedMail sends (docs/EKSIKLER.md aktivite/onay
// workflow §1/§2/§3) — a genuine browser-fillable page (works from any
// device the student opens their email on, not just inside the Flutter
// app), verified by Laravel's own signed-URL HMAC rather than a
// hand-rolled token.
class ActivityFormController extends Controller
{
    public function show(Request $request, Event $event)
    {
        if ($event->workflow_status !== 'form_required') {
            return View::make('activity-form.unavailable', ['event' => $event]);
        }
        if ($event->form_opened_at === null) {
            $event->update(['form_opened_at' => now()]);
        }

        return View::make('activity-form.show', ['event' => $event]);
    }

    public function submit(Request $request, Event $event)
    {
        if ($event->workflow_status !== 'form_required') {
            return View::make('activity-form.unavailable', ['event' => $event]);
        }

        $data = $request->validate([
            'studentNumber' => 'required|string|max:50',
            'phone' => 'required|string|max:30',
            'faculty' => 'required|string|max:120',
            'department' => 'required|string|max:120',
            'endTime' => 'nullable|string|max:20',
            'estimatedAttendees' => 'nullable|integer|min:1',
            'targetAudience' => 'nullable|string|max:120',
            'purpose' => 'required|string|max:2000',
            'requirements' => 'nullable|string|max:2000',
            'poster' => 'nullable|image|max:8192',
        ]);

        $posterUrl = null;
        if ($request->hasFile('poster')) {
            $path = $request->file('poster')->store('activity-posters', 'public');
            $posterUrl = '/storage/'.$path;
        }

        $staff = AcademicRoutingService::routeFor($data['department'], $data['faculty']);

        $event->update([
            'student_number' => $data['studentNumber'],
            'phone' => $data['phone'],
            'faculty' => $data['faculty'],
            'department' => $data['department'],
            'end_time' => $data['endTime'] ?? null,
            'estimated_attendees' => $data['estimatedAttendees'] ?? null,
            'audience' => ($data['targetAudience'] ?? null) ?: $event->audience,
            'purpose' => $data['purpose'],
            'requirements' => $data['requirements'] ?? null,
            'poster_url' => $posterUrl ?? $event->poster_url,
            'assigned_staff_id' => $staff?->id,
            'workflow_status' => 'pending_approval',
        ]);

        if ($event->created_by_user_id) {
            ActivityLogger::log($event->created_by_user_id, 'eventJoin',
                "Formunu doldurdun: {$event->title}", 'Değerlendiriliyor');
        }
        AuditLogger::log($event->organizer ?: 'öğrenci', 'form_submitted', 'event',
            "{$event->title} (yönlendirildi: ".($staff?->name ?? 'atanmadı').')');

        // The real student who proposed this, not the club/organizer
        // contact (organizer_email) — those are different people for a
        // student-authored own-activity.
        $student = $event->creator;
        if ($student?->email) {
            EmailService::send($student->email, "Formunuz gönderildi: {$event->title}",
                'event-activity-form-submitted', new ActivityFormSubmittedMail($event));
        }
        if ($staff?->email) {
            EmailService::send($staff->email, "Onayınız bekleniyor: {$event->title}",
                'event-activity-pending-approval', new ActivityPendingApprovalMail($event));
        }

        return View::make('activity-form.submitted', ['event' => $event]);
    }
}
