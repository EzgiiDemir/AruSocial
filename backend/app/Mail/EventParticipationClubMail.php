<?php

namespace App\Mail;

use App\Models\Event;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

// Mail 1 of the 2-stage katılım workflow (docs/EKSIKLER.md §6): the club
// president learns a student wants to join.
class EventParticipationClubMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Event $event, public User $student, public ?string $participationLabel = null) {}

    public function build(): self
    {
        return $this
            ->subject("Yeni katılım talebi: {$this->event->title}")
            ->view('emails.event-participation-club');
    }
}
