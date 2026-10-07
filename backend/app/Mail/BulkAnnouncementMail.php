<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// Toplu e-posta (docs/EKSIKLER.md §6/§10) — one club/event announcement
// sent to a real list of recipient addresses, one real Mail send per
// address, each logged in email_logs individually.
class BulkAnnouncementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $subjectLine, public string $bodyText) {}

    public function build(): self
    {
        return $this
            ->subject($this->subjectLine)
            ->view('emails.bulk-announcement');
    }
}
