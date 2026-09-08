<?php

namespace App\Http\Controllers\Api;

use App\Events\AppointmentChanged;
use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Api\Concerns\ModeratesContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\BookAppointmentRequest;
use App\Http\Requests\UpdateAppointmentRequest;
use App\Http\Requests\UpsertStaffSlotRequest;
use App\Models\Appointment;
use App\Models\Notification as InboxNotification;
use App\Models\ParticipationApplication;
use App\Models\StaffAvailabilitySlot;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\GranularPermissions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AppointmentController extends Controller
{
    use ApiResponds, ModeratesContent;

    public function slots(Request $request, string $staffId): JsonResponse
    {
        $staff = StaffProfile::where('id', $staffId)->where('active', true)->first();
        if (! $staff) {
            return $this->fail(404, 'STAFF_NOT_FOUND', 'Staff not found.');
        }
        $date = $request->query('date') ?: now()->toDateString();
        $this->ensureDefaultSlots($staffId, $date);
        $slots = StaffAvailabilitySlot::where('staff_profile_id', $staffId)
            ->whereDate('slot_date', $date)
            ->orderBy('start_time')
            ->get();

        $bookedKeys = Appointment::where('staff_profile_id', $staffId)
            ->whereDate('slot_date', $date)
            ->whereIn('status', Appointment::ACTIVE_STATUSES)
            ->get()
            ->map(fn ($a) => substr((string) $a->start_time, 0, 5))
            ->all();

        return $this->ok($slots->map(function (StaffAvailabilitySlot $s) use ($bookedKeys) {
            $start = substr((string) $s->start_time, 0, 5);

            return $s->toApiArray(in_array($start, $bookedKeys, true));
        }));
    }

    public function upsertSlot(UpsertStaffSlotRequest $request, string $staffId): JsonResponse
    {
        if (! StaffProfile::where('id', $staffId)->exists()) {
            return $this->fail(404, 'STAFF_NOT_FOUND', 'Staff not found.');
        }
        $id = $request->input('id') ?: 'slot-'.Str::uuid();
        $slot = StaffAvailabilitySlot::updateOrCreate(['id' => $id], [
            'staff_profile_id' => $staffId,
            'slot_date' => $request->input('date'),
            'start_time' => $request->input('startTime'),
            'end_time' => $request->input('endTime'),
            'is_blocked' => $request->boolean('isBlocked'),
        ]);
        AuditLogger::logAsCurrentUser('upsert', 'staff_slot', "{$staffId} {$slot->slot_date}");

        return $this->ok($slot->toApiArray(), 201);
    }

    public function destroySlot(string $staffId, string $slotId): JsonResponse
    {
        StaffAvailabilitySlot::where('id', $slotId)->where('staff_profile_id', $staffId)->delete();

        return $this->ok(['deleted' => true]);
    }

    public function mine(): JsonResponse
    {
        $me = $this->currentUser();
        $rows = Appointment::with(['staff', 'student'])
            ->where('student_user_id', $me->id)
            ->orderByDesc('slot_date')
            ->get();

        return $this->ok($rows->map->toApiArray());
    }

    public function show(string $id): JsonResponse
    {
        $me = $this->currentUser();
        $appt = Appointment::with(['staff', 'student'])->find($id);
        if (! $appt) {
            return $this->fail(404, 'APPOINTMENT_NOT_FOUND', 'Appointment not found.');
        }
        if (! $this->mayView($me, $appt)) {
            return $this->fail(404, 'APPOINTMENT_NOT_FOUND', 'Appointment not found.');
        }

        return $this->ok($appt->toApiArray());
    }

    public function book(BookAppointmentRequest $request): JsonResponse
    {
        $me = $this->currentUser();
        $staffId = $request->input('staffProfileId');
        $date = $request->input('date');
        $start = $this->normalizeTime($request->input('startTime'));
        $end = $this->normalizeTime($request->input('endTime'));
        if ($end === '') {
            $end = $this->addMinutes($start, 30);
        }
        $subject = trim((string) $request->input('subject'));
        $notes = $request->input('notes');

        $staff = StaffProfile::where('id', $staffId)->where('active', true)->first();
        if (! $staff) {
            return $this->fail(404, 'STAFF_NOT_FOUND', 'Staff not found.');
        }

        $this->ensureDefaultSlots($staffId, $date);
        $slot = $this->findSlot($staffId, $date, $start);
        if (! $slot) {
            $slot = $this->createSlot($staffId, $date, $start, $end);
        }
        if (! $slot || $slot->is_blocked) {
            return $this->fail(409, 'SLOT_UNAVAILABLE', 'This slot is not available.');
        }

        try {
            $slotStart = \Carbon\Carbon::parse("{$date} {$start}");
            if ($slotStart->isPast()) {
                return $this->fail(409, 'PAST_SLOT', 'Past slots cannot be booked.');
            }
        } catch (\Throwable) {
            // date/time already validated by FormRequest
        }

        $already = Appointment::where('student_user_id', $me->id)
            ->whereDate('slot_date', $date)
            ->whereIn('status', Appointment::ACTIVE_STATUSES)
            ->get()
            ->contains(fn (Appointment $a) => $this->normalizeTime($a->start_time) === $start);
        if ($already) {
            return $this->fail(409, 'APPOINTMENT_ALREADY_EXISTS', 'You already have an appointment in this slot.');
        }

        $applicationId = $request->input('applicationId');
        if ($applicationId) {
            $owns = ParticipationApplication::where('id', $applicationId)
                ->where('user_id', $me->id)
                ->exists();
            if (! $owns) {
                return $this->fail(404, 'APPLICATION_NOT_FOUND', 'Application not found.');
            }
        }

        try {
            $appointment = DB::transaction(function () use ($me, $staffId, $date, $start, $end, $applicationId, $subject, $notes) {
                $slot = $this->findSlot($staffId, $date, $start);
                if ($slot) {
                    $slot = StaffAvailabilitySlot::where('id', $slot->id)->lockForUpdate()->first();
                }
                if (! $slot || $slot->is_blocked) {
                    throw new \RuntimeException('SLOT_UNAVAILABLE');
                }

                $exists = Appointment::where('staff_profile_id', $staffId)
                    ->whereDate('slot_date', $date)
                    ->whereIn('status', Appointment::ACTIVE_STATUSES)
                    ->lockForUpdate()
                    ->get()
                    ->contains(fn (Appointment $a) => $this->normalizeTime($a->start_time) === $start);
                if ($exists) {
                    throw new \RuntimeException('SLOT_UNAVAILABLE');
                }

                return Appointment::create([
                    'id' => 'appt-'.Str::uuid(),
                    'staff_profile_id' => $staffId,
                    'student_user_id' => $me->id,
                    'slot_date' => $date,
                    'start_time' => $start,
                    'end_time' => $end,
                    'application_id' => $applicationId,
                    'subject' => $subject,
                    'notes' => $notes,
                    'status' => 'pending',
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->fail(409, 'SLOT_UNAVAILABLE', 'This slot was just taken.');
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'SLOT_UNAVAILABLE') {
                return $this->fail(409, 'SLOT_UNAVAILABLE', 'This slot was just taken.');
            }
            throw $e;
        }

        $appointment->load(['staff', 'student']);
        $this->broadcastChange($appointment);
        InboxNotification::create([
            'id' => 'notif-'.Str::uuid(),
            'user_id' => $me->id,
            'actor_user_id' => $me->id,
            'kind' => 'appointment_booked',
            'title' => 'Randevu talebiniz alındı',
            'body' => "{$staff->name} · {$date} {$start} · {$subject}",
            'created_at' => now(),
        ]);
        if ($staff->user_id) {
            $owner = User::find($staff->user_id);
            if ($owner) {
                InboxNotification::notify(
                    $owner,
                    $me,
                    'appointment_booked',
                    'Yeni randevu talebi',
                    "{$me->name} · {$date} {$start} · {$subject}",
                );
            }
        }

        return $this->ok($appointment->toApiArray(), 201);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $query = Appointment::with(['staff', 'student'])
            ->orderByDesc('created_at')
            ->orderByDesc('slot_date')
            ->orderBy('start_time');
        if ($staffId = $request->query('staffProfileId')) {
            $query->where('staff_profile_id', $staffId);
        }
        if ($status = $request->query('status')) {
            if ($status === 'approved') {
                $query->whereIn('status', ['approved', 'booked']);
            } else {
                $query->where('status', $status);
            }
        }
        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('subject', 'like', "%{$q}%")
                    ->orWhere('notes', 'like', "%{$q}%")
                    ->orWhereHas('student', fn ($s) => $s->where('name', 'like', "%{$q}%"))
                    ->orWhereHas('staff', fn ($s) => $s->where('name', 'like', "%{$q}%")
                        ->orWhere('department', 'like', "%{$q}%"));
            });
        }
        if ($department = $request->query('department')) {
            $query->whereHas('staff', fn ($s) => $s->where('department', $department));
        }
        if ($from = $request->query('from')) {
            $query->whereDate('slot_date', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('slot_date', '<=', $to);
        }

        return $this->ok($query->limit(200)->get()->map->toApiArray());
    }

    public function adminUpdate(UpdateAppointmentRequest $request, string $id): JsonResponse
    {
        $appt = Appointment::with(['staff', 'student'])->find($id);
        if (! $appt) {
            return $this->fail(404, 'APPOINTMENT_NOT_FOUND', 'Appointment not found.');
        }

        $updates = [];
        if ($request->exists('adminNotes')) {
            $updates['admin_notes'] = $request->input('adminNotes');
        }
        if ($request->exists('notes')) {
            $updates['notes'] = $request->input('notes');
        }
        if ($staffId = $request->input('staffProfileId')) {
            $staff = StaffProfile::where('id', $staffId)->where('active', true)->first();
            if (! $staff) {
                return $this->fail(404, 'STAFF_NOT_FOUND', 'Staff not found.');
            }
            $updates['staff_profile_id'] = $staffId;
        }
        if ($request->exists('status')) {
            $status = $request->input('status');
            if ($status === 'booked') {
                $status = 'approved';
            }
            $updates['status'] = $status;
        }

        $appt->update($updates);
        $fresh = $appt->fresh(['staff', 'student']);
        $this->broadcastChange($fresh);
        AuditLogger::logAsCurrentUser('update', 'appointment', $fresh->id);

        $student = $fresh->student;
        if ($student && $request->exists('status')) {
            InboxNotification::notify(
                $student,
                $this->currentUser(),
                'appointment_updated',
                'Randevu durumu güncellendi',
                ($fresh->subject ?: 'Randevu').' · '.$fresh->status,
            );
        }

        return $this->ok($fresh->toApiArray());
    }

    public function cancel(string $id): JsonResponse
    {
        $me = $this->currentUser();
        $appt = Appointment::with(['staff', 'student'])->find($id);
        if (! $appt || ! $this->mayCancel($me, $appt)) {
            return $this->fail(404, 'APPOINTMENT_NOT_FOUND', 'Appointment not found.');
        }
        if (! Appointment::occupiesSlot($appt->status)) {
            return $this->fail(409, 'INVALID_STATE', 'Appointment cannot be cancelled.');
        }
        if ($appt->slot_date && $appt->slot_date->isPast()) {
            return $this->fail(409, 'PAST_SLOT', 'Past appointments cannot be cancelled.');
        }
        $appt->update(['status' => 'cancelled']);
        $fresh = $appt->fresh(['staff', 'student']);
        $this->broadcastChange($fresh);

        $when = trim(($fresh->slot_date?->toDateString() ?? '').' '.substr((string) $fresh->start_time, 0, 5));
        $student = $fresh->student;
        $staff = $fresh->staff;
        if ($student && (int) $student->id !== (int) $me->id) {
            InboxNotification::notify(
                $student,
                $me,
                'appointment_cancelled',
                'Randevu iptal edildi',
                ($staff?->name ?? 'Personel').' · '.$when,
            );
        }
        if ($staff?->user_id && (int) $staff->user_id !== (int) $me->id) {
            $owner = User::find($staff->user_id);
            if ($owner) {
                InboxNotification::notify(
                    $owner,
                    $me,
                    'appointment_cancelled',
                    'Randevu iptal edildi',
                    ($student?->name ?? 'Öğrenci').' · '.$when,
                );
            }
        }

        return $this->ok($fresh->toApiArray());
    }

    private function broadcastChange(Appointment $appointment): void
    {
        try {
            broadcast(new AppointmentChanged($appointment));
        } catch (\Throwable) {
            // Reverb/Pusher down must not fail the REST booking; DB is source of truth.
        }
    }

    private function normalizeTime(?string $time): string
    {
        $raw = trim((string) $time);
        if ($raw === '') {
            return '';
        }

        return substr($raw, 0, 5);
    }

    private function addMinutes(string $hhmm, int $minutes): string
    {
        try {
            return \Carbon\Carbon::createFromFormat('H:i', $hhmm)
                ->addMinutes($minutes)
                ->format('H:i');
        } catch (\Throwable) {
            return $hhmm;
        }
    }

    private function findSlot(string $staffId, string $date, string $start): ?StaffAvailabilitySlot
    {
        $want = $this->normalizeTime($start);

        return StaffAvailabilitySlot::where('staff_profile_id', $staffId)
            ->whereDate('slot_date', $date)
            ->get()
            ->first(fn (StaffAvailabilitySlot $s) => $this->normalizeTime($s->start_time) === $want);
    }

    private function createSlot(string $staffId, string $date, string $start, string $end): ?StaffAvailabilitySlot
    {
        $start = $this->normalizeTime($start);
        $end = $this->normalizeTime($end) ?: $this->addMinutes($start, 30);
        try {
            return StaffAvailabilitySlot::create([
                'id' => 'slot-'.substr(sha1($staffId.'|'.$date.'|'.$start), 0, 20),
                'staff_profile_id' => $staffId,
                'slot_date' => $date,
                'start_time' => $start,
                'end_time' => $end,
                'is_blocked' => false,
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->findSlot($staffId, $date, $start);
        }
    }

    private function ensureDefaultSlots(string $staffId, string $date): void
    {
        $exists = StaffAvailabilitySlot::where('staff_profile_id', $staffId)
            ->whereDate('slot_date', $date)
            ->exists();
        if ($exists) {
            return;
        }

        $now = now();
        $rows = [];
        for ($hour = 9; $hour < 17; $hour++) {
            foreach ([0, 30] as $minute) {
                $start = sprintf('%02d:%02d', $hour, $minute);
                $end = $this->addMinutes($start, 30);
                $rows[] = [
                    'id' => 'slot-'.substr(sha1($staffId.'|'.$date.'|'.$start), 0, 20),
                    'staff_profile_id' => $staffId,
                    'slot_date' => $date,
                    'start_time' => $start,
                    'end_time' => $end,
                    'is_blocked' => false,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        try {
            StaffAvailabilitySlot::insert($rows);
        } catch (\Throwable) {
            // Concurrent request already generated this day's office hours.
        }
    }

    private function mayView(User $me, Appointment $appt): bool
    {
        if ((int) $appt->student_user_id === (int) $me->id) {
            return true;
        }
        if (GranularPermissions::allows($me, 'appointments.manage')) {
            return true;
        }

        return StaffProfile::where('id', $appt->staff_profile_id)
            ->where('user_id', $me->id)
            ->exists();
    }

    private function mayCancel(User $me, Appointment $appt): bool
    {
        return $this->mayView($me, $appt);
    }
}
