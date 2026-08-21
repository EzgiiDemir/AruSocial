<?php

namespace App\Mail;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// docs/EKSIKLER.md aktivite/onay workflow §4/§5 — the real approval email,
// sent the moment the activity actually goes live, not just logged.
class ActivityApprovedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Event $event)
    {
    }

    public function build(): self
    {
        return $this
            ->subject("Aktiviteniz onaylandı: {$this->event->title}")
            ->view('emails.activity-approved');
    }
}
