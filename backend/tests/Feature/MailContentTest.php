<?php

namespace Tests\Feature;

use App\Mail\BulkAnnouncementMail;
use App\Mail\EventParticipationClubMail;
use App\Mail\EventParticipationFormCompletedMail;
use App\Mail\EventParticipationFormMail;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_announcement_keeps_subject_and_body(): void
    {
        $mail = new BulkAnnouncementMail('Konu satırı', "Satır 1\nSatır 2");
        $mail->build();

        $this->assertSame('Konu satırı', $mail->subject);
        $html = $mail->render();
        $this->assertStringContainsString('Satır 1', $html);
        $this->assertStringContainsString('Satır 2', $html);
        $this->assertStringContainsString('AruSocial', $html);
    }

    public function test_participation_mailables_keep_event_title_in_subject_and_body(): void
    {
        $event = Event::create([
            'id' => 'e-mail-content',
            'title' => 'Bahar Şenliği',
            'time' => '18:00',
            'place_name' => 'Bahçe',
            'category' => 'C',
        ]);
        $student = User::create([
            'name' => 'Ege Aydın',
            'email' => 'ege.content@arucad.edu.tr',
            'password' => bcrypt('x'),
        ]);

        $form = (new EventParticipationFormMail($event))->build();
        $this->assertSame('Katılım formu: Bahar Şenliği', $form->subject);
        $this->assertStringContainsString('Bahar Şenliği', $form->render());

        $club = (new EventParticipationClubMail($event, $student, 'Gönüllü'))->build();
        $this->assertSame('Yeni katılım talebi: Bahar Şenliği', $club->subject);
        $this->assertStringContainsString('Bahar Şenliği', $club->render());

        $done = (new EventParticipationFormCompletedMail($event, $student, 'Gönüllü'))->build();
        $this->assertSame('Onay bekliyor: Bahar Şenliği', $done->subject);
        $this->assertStringContainsString('Bahar Şenliği', $done->render());
    }
}
