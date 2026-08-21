<?php

namespace App\Mail;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// docs/EKSIKLER.md aktivite/onay workflow §4/§5 — includes the real
// rejection reason an admin actually entered, not a generic "declined".
class ActivityRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Event $event, public string $reviewNote)
    {
    }

    public function build(): self
    {
        return $this
            ->subject("Aktivite değerlendirmesi: {$this->event->title}")
            ->view('emails.activity-rejected');
    }
}
