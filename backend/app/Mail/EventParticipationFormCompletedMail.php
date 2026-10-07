<?php

namespace App\Mail;

use App\Models\Event;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// Mail 3 of the katılım workflow (docs/EKSIKLER.md §5): fires only once
// the student has actually completed the form — this is what makes the
// join actionable for the teacher/club, not the initial join request.
class EventParticipationFormCompletedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Event $event, public User $student, public ?string $participationLabel = null) {}

    public function build(): self
    {
        return $this
            ->subject("Onay bekliyor: {$this->event->title}")
            ->view('emails.event-participation-form-completed');
    }
}
