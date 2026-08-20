<?php

namespace App\Mail;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// Mail 2 of the 2-stage katılım workflow: the student gets the form to
// fill in to actually confirm their spot.
class EventParticipationFormMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Event $event, public ?string $formUrl = null)
    {
    }

    public function build(): self
    {
        return $this
            ->subject("Katılım formu: {$this->event->title}")
            ->view('emails.event-participation-form');
    }
}
