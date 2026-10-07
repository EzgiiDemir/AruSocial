<?php

namespace App\Services\Agent;

use App\Models\Appointment;
use App\Models\Club;
use App\Models\ClubMember;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * What the assistant may know about the student it is talking to.
 *
 * "When is my next appointment", "which of my clubs meets this week",
 * "how much XP do I need for the next level" are the questions that make
 * an assistant feel like it is helping *you* rather than reciting a
 * prospectus. None of them could be answered before, because every tool
 * the agent had returned campus-wide data.
 *
 * THE RULE THIS CLASS EXISTS TO ENFORCE: every query is scoped to the
 * authenticated user's own id, taken from the session — never from
 * anything the question, the model or the client supplied. A student who
 * asks "show me Ayşe's appointments" gets their own, because there is no
 * code path here that can be pointed at somebody else. The model cannot
 * widen the scope because it never chooses the scope.
 *
 * What is deliberately NOT here: anything that is sensitive beyond
 * scheduling. No moderation record, no strikes, no violation history, no
 * grades, no messages, no other people's names from a shared context. A
 * student asking the assistant a casual question has not agreed to have
 * their disciplinary file read back at them, and a prompt is not a safe
 * place to put it — prompts get logged, cached and sent to a provider.
 */
class PersonalContext
{
    /**
     * Personal rows are useful only when the student actually asks about
     * their own campus life. Attaching profile, appointments and memberships
     * to every casual campus question both wastes context and unnecessarily
     * turns a public question into personal data.
     */
    public function isRelevant(string $query): bool
    {
        $folded = \App\Support\TextFold::fold($query);
        foreach ([
            'randevum', 'randevularim', 'appointment', 'appointments',
            'kulubum', 'kuluplerim', 'uyeliklerim', 'my club', 'my clubs',
            'bolumum', 'sinifim', 'seviyem', 'xp', 'profilim',
            'my department', 'my year', 'my level', 'my profile',
            'etkinliklerim', 'katildigim etkinlik', 'my events',
            'моя встреча', 'мои встречи', 'мои клубы', 'мой факультет',
            'мой профиль', 'мои мероприятия',
        ] as $term) {
            if (str_contains($folded, \App\Support\TextFold::fold($term))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compact, factual lines about this student, or [] when there is
     * nothing to say.
     *
     * @return list<string>
     */
    public function lines(?User $user, Carbon $now): array
    {
        if ($user === null) {
            return [];
        }

        return array_values(array_filter(array_merge(
            $this->profile($user),
            $this->appointments($user, $now),
            $this->clubs($user),
            $this->events($user, $now),
        )));
    }

    /** @return list<string> */
    private function profile(User $user): array
    {
        $bits = array_filter([
            $user->department ? 'bölüm: '.$user->department : null,
            $user->year ? 'sınıf/yıl: '.$user->year : null,
            $user->level !== null ? 'seviye: '.$user->level : null,
            $user->xp !== null ? 'XP: '.$user->xp : null,
        ]);

        return $bits === [] ? [] : ['- Profil — '.implode(', ', $bits)];
    }

    /**
     * Upcoming appointments only.
     *
     * Past ones are not context for "what is coming up", and listing a
     * cancelled slot as though it stands would be worse than saying
     * nothing.
     *
     * @return list<string>
     */
    private function appointments(User $user, Carbon $now): array
    {
        $rows = Appointment::query()
            ->where('student_user_id', $user->id)
            ->whereIn('status', ['confirmed', 'pending'])
            ->whereDate('slot_date', '>=', $now->toDateString())
            ->orderBy('slot_date')
            ->orderBy('start_time')
            ->limit(5)
            ->get();

        $lines = [];
        foreach ($rows as $appointment) {
            $when = optional($appointment->slot_date)->format('d.m.Y');
            $time = trim((string) $appointment->start_time);
            $subject = trim((string) $appointment->subject);

            $lines[] = '- Randevun: '.$when.($time !== '' ? ' '.$time : '')
                .($subject !== '' ? ' — '.$subject : '')
                .' ('.$appointment->status.')';
        }

        return $lines;
    }

    /** @return list<string> */
    private function clubs(User $user): array
    {
        $ids = ClubMember::query()->where('user_id', $user->id)->pluck('club_id');
        if ($ids->isEmpty()) {
            return [];
        }

        $names = Club::query()->whereIn('id', $ids)->pluck('name')->all();

        return $names === [] ? [] : ['- Üyesi olduğun kulüpler: '.implode(', ', $names)];
    }

    /**
     * The next few events, so "what is on this week" is answerable at
     * all. Campus-wide rather than personal, but placed here because it
     * is only useful relative to today.
     *
     * @return list<string>
     */
    private function events(User $user, Carbon $now): array
    {
        $rows = Event::query()
            ->publiclyListed()
            ->whereDate('event_date', '>=', $now->toDateString())
            ->whereDate('event_date', '<=', $now->copy()->addDays(7)->toDateString())
            ->orderBy('event_date')
            ->limit(5)
            ->get();

        $lines = [];
        foreach ($rows as $event) {
            $lines[] = '- Bu hafta: '.$event->title
                .' — '.optional($event->event_date)->format('d.m.Y')
                .(trim((string) $event->time) !== '' ? ' '.$event->time : '')
                .(trim((string) $event->place_name) !== '' ? ' @ '.$event->place_name : '');
        }

        return $lines;
    }
}
