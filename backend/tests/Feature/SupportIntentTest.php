<?php

namespace Tests\Feature;

use App\Models\ServiceItem;
use App\Services\Agent\AruverseAgent;
use App\Services\CampusAskFallback;
use App\Support\SupportIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A student wrote "psikolojik sorunum var" and AICAD answered "Bunu kayıtta net
 * eşleştiremedim. Denemek için bir yer adı yaz: The Garden, ARUCAD Dormitory,
 * Age of Bronze…" with directions buttons, because the degraded fallback
 * treated every question as a place lookup and no agent keyword covered
 * distress — while ARUCAD has a counselling centre the whole time.
 *
 * This path must hold with the AI provider down, which is when it happened.
 */
class SupportIntentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        ServiceItem::create([
            'id' => 'pdr',
            'title' => 'Psikolojik Danışmanlık Merkezi (PDR)',
            'category' => 'Wellbeing',
            'description' => 'Bireysel ve grup danışmanlığı; gizlilik esaslı, gönüllü destek.',
            'contact' => 'destek@arucad.edu.tr',
            'building' => 'Minotaur',
            'hours' => 'Randevu ile',
        ]);
    }

    public function test_distress_is_answered_with_the_counselling_service(): void
    {
        $answer = app(CampusAskFallback::class)->answer('psikolojik sorunum var');

        $this->assertStringContainsString('Psikolojik Danışmanlık Merkezi', $answer);
        $this->assertStringContainsString('destek@arucad.edu.tr', $answer);
        $this->assertStringContainsString('Minotaur', $answer);
    }

    /** The exact failure: never answer a feeling with a list of buildings. */
    public function test_distress_is_never_answered_with_building_names(): void
    {
        $answer = app(CampusAskFallback::class)->answer('psikolojik sorunum var');

        foreach (['The Garden', 'Age of Bronze', 'ARUCAD Dormitory', 'bir yer adı yaz'] as $wrong) {
            $this->assertStringNotContainsString($wrong, $answer);
        }
    }

    public function test_it_answers_in_the_language_the_student_used(): void
    {
        $answer = app(CampusAskFallback::class)->answer('I feel depressed and lonely');

        $this->assertStringContainsString('You are not alone', $answer);
        $this->assertStringContainsString('destek@arucad.edu.tr', $answer);
    }

    public function test_crisis_language_is_urgent_and_invents_no_phone_number(): void
    {
        $intent = app(SupportIntent::class);
        $this->assertTrue($intent->isCrisis('kendime zarar vermeyi düşünüyorum'));

        $answer = app(CampusAskFallback::class)->answer('kendime zarar vermeyi düşünüyorum');

        $this->assertStringContainsString('şimdi bir insana ulaş', $answer);
        // No fabricated hotline: only the real, stored contact may appear.
        $this->assertSame(0, preg_match('~\b\d{3,4}\s?\d{3,}\b~', $answer));
    }

    public function test_ordinary_questions_are_not_treated_as_distress(): void
    {
        $intent = app(SupportIntent::class);

        $this->assertFalse($intent->matches('kütüphane nerede'));
        $this->assertFalse($intent->matches('ne burslarınız var'));
        $this->assertFalse($intent->matches('yemekhane saat kaçta açılıyor'));
    }

    public function test_the_agent_also_surfaces_support_services_to_the_model(): void
    {
        $ctx = app(AruverseAgent::class)->buildContext('psikolojik sorunum var');

        $this->assertContains('support', $ctx['tools']);
        $this->assertStringContainsString('Psikolojik Danışmanlık Merkezi', $ctx['context']);
        $this->assertStringContainsString('destek@arucad.edu.tr', $ctx['context']);
    }

    /** With no wellbeing service configured, invent nothing. */
    public function test_without_a_configured_service_it_stays_silent(): void
    {
        ServiceItem::query()->delete();

        $this->assertNull(app(SupportIntent::class)->message('psikolojik sorunum var'));
    }
}
