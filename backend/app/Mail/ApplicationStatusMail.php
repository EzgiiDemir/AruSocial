<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ApplicationStatusMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $recipientName,
        public string $targetLabel,
        public string $statusLabel,
        public string $note,
        public string $appUrl,
    ) {
    }

    public function build(): self
    {
        return $this
            ->subject($this->subjectLine)
            ->view('emails.application-status');
    }
}
