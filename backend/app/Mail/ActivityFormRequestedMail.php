<?php

namespace App\Mail;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// Stage 1 of the real "Kendi Aktiviteni Oluştur" workflow (docs/EKSIKLER.md
// aktivite/onay workflow §2): sent the moment the initial activity record
// is created, with a real signed, single-activity form URL — not a fake
// "check your email" promise with nothing behind it.
class ActivityFormRequestedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Event $event, public string $formUrl)
    {
    }

    public function build(): self
    {
        return $this
            ->subject("Aktiviteniz oluşturuldu: {$this->event->title}")
            ->view('emails.activity-form-requested');
    }
}
