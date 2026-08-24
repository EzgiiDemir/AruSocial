<?php

namespace Tests\Feature;

use App\Mail\BulkAnnouncementMail;
use App\Mail\EventParticipationClubMail;
use App\Mail\EventParticipationFormCompletedMail;
use App\Mail\EventParticipationFormMail;
use App\Models\EmailLog;
use App\Models\Event;
use App\Services\EmailService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailSendTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_announcement_is_captured_for_the_intended_recipient(): void
    {
        Mail::fake();
        $this->actingAsRole();

        EmailService::send(
            'a@arucad.edu.tr',
            'Duyuru',
            'bulk-announcement',
            new BulkAnnouncementMail('Duyuru', 'Merhaba kampüs'),
        );

        Mail::assertSent(BulkAnnouncementMail::class, function (BulkAnnouncementMail $mail) {
            return $mail->hasTo('a@arucad.edu.tr')
                && $mail->subjectLine === 'Duyuru'
                && $mail->bodyText === 'Merhaba kampüs';
        });
        $this->assertDatabaseHas('email_logs', [
            'to_email' => 'a@arucad.edu.tr',
            'subject' => 'Duyuru',
            'template' => 'bulk-announcement',
            'status' => 'sent',
        ]);
    }

    public function test_event_participation_mailables_are_captured_without_smtp(): void
    {
        Mail::fake();
        $user = $this->actingAsUser();
        $event = Event::create([
            'id' => 'e-mail-send',
            'title' => 'Açık Stüdyo',
            'time' => '10:00',
            'place_name' => 'Garden',
            'category' => 'C',
        ]);

        EmailService::send($user->email, "Katılım formu: {$event->title}", 'event-participation-form', new EventParticipationFormMail($event));
        EmailService::send('club@arucad.edu.tr', "Yeni katılım talebi: {$event->title}", 'event-participation-club', new EventParticipationClubMail($event, $user, 'Gönüllü'));
        EmailService::send('club@arucad.edu.tr', "Onay bekliyor: {$event->title}", 'event-participation-form-completed', new EventParticipationFormCompletedMail($event, $user, 'Gönüllü'));

        Mail::assertSent(EventParticipationFormMail::class, fn ($m) => $m->hasTo($user->email));
        Mail::assertSent(EventParticipationClubMail::class, fn ($m) => $m->hasTo('club@arucad.edu.tr'));
        Mail::assertSent(EventParticipationFormCompletedMail::class, fn ($m) => $m->hasTo('club@arucad.edu.tr'));
        $this->assertSame(3, EmailLog::count());
        $this->assertSame(3, EmailLog::where('status', 'sent')->count());
    }
}
