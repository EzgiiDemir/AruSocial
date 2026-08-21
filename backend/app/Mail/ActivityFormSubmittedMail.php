<?php

namespace App\Mail;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// Stage 2 (docs/EKSIKLER.md aktivite/onay workflow §3/§5): confirms the
// real form submission and that a real, routed approver now has it.
class ActivityFormSubmittedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Event $event)
    {
    }

    public function build(): self
    {
        return $this
            ->subject("Formunuz gönderildi: {$this->event->title}")
            ->view('emails.activity-form-submitted');
    }
}
