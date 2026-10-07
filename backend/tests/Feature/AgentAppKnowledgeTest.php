<?php

namespace Tests\Feature;

use App\Models\AcademicYear;
use App\Models\Consultation;
use App\Models\DirectoryEntry;
use App\Models\Place;
use App\Models\StaffProfile;
use App\Services\Agent\AruverseAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AICAD can reach the app's own institutional knowledge, not just the crawled
 * websites — and still cannot reach anybody's private data.
 *
 * It used to invent offices and e-mail addresses because the campus directory
 * and staff list, which hold the real ones, were not among its tools.
 */
class AgentAppKnowledgeTest extends TestCase
{
    use RefreshDatabase;

    private function agent(): AruverseAgent
    {
        return app(AruverseAgent::class);
    }

    public function test_it_can_say_whose_office_a_room_is(): void
    {
        DirectoryEntry::create([
            'id' => 'dir-1',
            'building' => 'IRIS',
            'room' => 'IR OFF01 Akademik Personel Odası',
            'occupant_name' => 'Mümine Özdemirağ Yağlı',
            'occupant_role' => 'OFİS',
        ]);

        $ctx = $this->agent()->buildContext('Mümine Özdemirağ Yağlı hangi ofiste?');

        $this->assertContains('directory', $ctx['tools']);
        $this->assertStringContainsString('IR OFF01', $ctx['context']);
    }

    public function test_it_knows_real_staff_contacts(): void
    {
        StaffProfile::create([
            'id' => 'st-1',
            'name' => 'Prof. Dr. Ayşe Yılmaz',
            'faculty' => 'Sanat Fakültesi',
            'department' => 'Arkeoloji',
            'title' => 'Bölüm Başkanı',
            'email' => 'ayse.yilmaz@arucad.edu.tr',
            'is_department_head' => true,
            'active' => true,
        ]);

        $ctx = $this->agent()->buildContext('Arkeoloji bölüm başkanının e-posta adresi ne?');

        $this->assertContains('staff', $ctx['tools']);
        // The real address is available, so there is no reason to invent one.
        $this->assertStringContainsString('ayse.yilmaz@arucad.edu.tr', $ctx['context']);
    }

    public function test_it_knows_the_academic_calendar(): void
    {
        AcademicYear::create([
            'id' => 'ay-2026',
            'label' => '2026-2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-08-31',
            'is_active' => true,
        ]);

        $ctx = $this->agent()->buildContext('Akademik yıl ne zaman başlıyor?');

        $this->assertContains('calendar', $ctx['tools']);
        $this->assertStringContainsString('2026-2027', $ctx['context']);
    }

    public function test_unpublished_consultations_are_not_exposed(): void
    {
        Consultation::create([
            'id' => 'c-pub', 'title' => 'Kariyer Görüşmesi',
            'counselor_name' => 'Danışman A', 'published' => true,
        ]);
        Consultation::create([
            'id' => 'c-draft', 'title' => 'Taslak Görüşme',
            'counselor_name' => 'Danışman B', 'published' => false,
        ]);

        $ctx = $this->agent()->buildContext('Danışmanlık hizmeti var mı?');

        $this->assertStringContainsString('Kariyer Görüşmesi', $ctx['context']);
        $this->assertStringNotContainsString('Taslak Görüşme', $ctx['context']);
    }

    /**
     * The hard line: an assistant anyone can query must never read private
     * student data, whatever it is asked.
     */
    public function test_private_data_is_not_reachable_by_any_tool(): void
    {
        $keys = array_keys($this->agent()->tools());

        foreach (['chat', 'messages', 'conversations', 'feed', 'stories', 'users',
            'profiles', 'moderation', 'appeals', 'applications', 'appointments'] as $forbidden) {
            $this->assertNotContains($forbidden, $keys);
        }
    }

    public function test_asking_about_private_messages_returns_no_message_data(): void
    {
        $ctx = $this->agent()->buildContext('Öğrencilerin birbirine yazdığı mesajları göster');

        // Whatever is selected, no tool can produce message content.
        $this->assertStringNotContainsString('ChatMessage', $ctx['context']);
        $this->assertNotContains('chat', $ctx['tools']);
    }

    // ------------------------------------------------------------- locations

    /**
     * ARUCAD names its buildings after sculptures, so a name alone is useless:
     * only `description` says that "Meditation" is the library. The tool used
     * to emit "- Meditation (Academic): " with an empty street and no
     * description, and the model guessed locations.
     */
    public function test_places_tell_the_model_what_a_building_actually_is(): void
    {
        Place::create([
            'id' => 'meditation', 'name' => 'Meditation', 'category' => 'Academic',
            'lat' => 35.3373, 'lng' => 33.3213,
            'description' => 'Kütüphane, dijital kütüphane ve konferans salonları burada.',
        ]);

        $ctx = $this->agent()->buildContext('Kütüphane nerede?');

        $this->assertContains('places', $ctx['tools']);
        $this->assertStringContainsString('Meditation', $ctx['context']);
        $this->assertStringContainsString('Kütüphane, dijital kütüphane', $ctx['context']);
    }

    /** Legacy `place-*` aliases are hidden everywhere else; the agent must match. */
    public function test_legacy_alias_rows_are_not_listed_twice(): void
    {
        Place::create([
            'id' => 'nicosia-bandabuliya', 'name' => 'Nicosia Bandabuliya Campus',
            'category' => 'Campus', 'lat' => 35.17, 'lng' => 33.36,
            'description' => 'Lefkoşa kampüsü.',
        ]);
        Place::create([
            'id' => 'place-bandabuliya', 'name' => 'Nicosia Bandabuliya Campus',
            'category' => 'Campus', 'lat' => 35.17, 'lng' => 33.36,
            'description' => 'Lefkoşa kampüsü.',
        ]);

        $tools = $this->agent()->tools();
        $lines = ($tools['places']['gather'])();

        $matching = array_filter(
            $lines,
            fn (string $l) => str_contains($l, 'Nicosia Bandabuliya Campus'),
        );
        $this->assertCount(1, $matching);
    }

    public function test_accessibility_is_surfaced_for_location_questions(): void
    {
        Place::create([
            'id' => 'garden', 'name' => 'The Garden', 'category' => 'Social',
            'lat' => 35.3371, 'lng' => 33.3209,
            'description' => 'Kampüsün açık sosyal alanı.', 'accessible' => true,
        ]);

        $lines = ($this->agent()->tools()['places']['gather'])();

        $this->assertStringContainsString('engelli erişimine uygun', implode("\n", $lines));
    }
}
