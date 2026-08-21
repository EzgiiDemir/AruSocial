<?php

namespace App\Mail;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// Notifies the real, auto-routed academic staff member (docs/EKSIKLER.md
// aktivite/onay workflow §3/§7) that a submitted activity is now waiting
// for them in the Admin Panel's "Bekleyen Aktiviteler" — the moment the
// form is actually completed, not when the (unrouted) record was first
// created.
class ActivityPendingApprovalMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Event $event)
    {
    }

    public function build(): self
    {
        return $this
            ->subject("Onayınız bekleniyor: {$this->event->title}")
            ->view('emails.activity-pending-approval');
    }
}
