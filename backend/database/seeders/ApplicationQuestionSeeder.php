<?php

namespace Database\Seeders;

use App\Models\ApplicationQuestion;
use Illuminate\Database\Seeder;

// Real starter question sets for the two-stage apply flow, one per
// target type — never hardcoded into application code; a staff member
// with `applications.manage` can add/reorder/retire from here via
// Admin Panel → Applications → Sorular. Basic student identity (name/
// email/department) is already known from the authenticated account, so
// none of these ask for it again — every question here is the
// category-specific, decision-relevant kind the brief asks for.
class ApplicationQuestionSeeder extends Seeder
{
    public function run(): void
    {
        $rows = [
            // ---- help / service (Yardım Al) ----
            ['aq-help-preview-topic', 'help', 'preview', 'textarea', 'Hangi konuda yardıma ihtiyacınız var?', null, null, true, 1],
            ['aq-help-preview-reason', 'help', 'preview', 'textarea', 'Bu ihtiyacın temel nedeni nedir?', null, null, true, 2],
            ['aq-help-preview-urgency', 'help', 'preview', 'single_choice', 'Ne kadar acil?', null, ['Bugün', 'Bu hafta', 'Bu ay'], true, 3],
            ['aq-help-detail-phone', 'help', 'detail', 'text', 'Telefon numaranız', null, null, true, 1],
            ['aq-help-detail-before', 'help', 'detail', 'single_choice', 'Daha önce bu konuda başvurdunuz mu?', null, ['Evet', 'Hayır'], false, 2],
            ['aq-help-detail-explain', 'help', 'detail', 'textarea', 'Durumunuzu detaylı açıklayın', null, null, true, 3],
            ['aq-help-detail-availability', 'help', 'detail', 'text', 'Uygun görüşme saatleriniz', null, null, false, 4],

            // ---- service (Kampüs Hizmetleri genel) — mirrors help ----
            ['aq-service-preview-topic', 'service', 'preview', 'textarea', 'Hangi konuda yardıma ihtiyacınız var?', null, null, true, 1],
            ['aq-service-preview-reason', 'service', 'preview', 'textarea', 'Bu ihtiyacın temel nedeni nedir?', null, null, true, 2],
            ['aq-service-detail-phone', 'service', 'detail', 'text', 'Telefon numaranız', null, null, true, 1],
            ['aq-service-detail-explain', 'service', 'detail', 'textarea', 'Durumunuzu detaylı açıklayın', null, null, true, 2],

            // ---- sport (Spor Kulüpleri) ----
            ['aq-sport-preview-before', 'sport', 'preview', 'single_choice', 'Bu spor dalıyla daha önce ilgilendiniz mi?', null, ['Evet', 'Hayır'], true, 1],
            ['aq-sport-preview-purpose', 'sport', 'preview', 'single_choice', 'Katılım amacınız nedir?', null, ['Rekabetçi', 'Hobi', 'Sosyalleşme'], true, 2],
            ['aq-sport-preview-level', 'sport', 'preview', 'single_choice', 'Deneyim seviyeniz', null, ['Başlangıç', 'Orta', 'İleri'], false, 3],
            ['aq-sport-detail-availability', 'sport', 'detail', 'text', 'Uygun antrenman zamanlarınız', null, null, true, 1],
            ['aq-sport-detail-health', 'sport', 'detail', 'textarea', 'Sağlık durumunuzla ilgili belirtmek istediğiniz bir şey var mı?', null, null, false, 2],
            ['aq-sport-detail-emergency', 'sport', 'detail', 'text', 'Acil durum iletişim', null, null, true, 3],
            ['aq-sport-detail-experience', 'sport', 'detail', 'textarea', 'Daha önceki spor deneyiminiz', null, null, false, 4],

            // ---- career (Career Kampüs) ----
            ['aq-career-preview-field', 'career', 'preview', 'text', 'İlgilendiğiniz kariyer alanı', null, null, true, 1],
            ['aq-career-preview-goal', 'career', 'preview', 'textarea', 'Kariyer hedefiniz', null, null, true, 2],
            ['aq-career-preview-why', 'career', 'preview', 'textarea', 'Neden bu fırsata başvuruyorsunuz?', null, null, true, 3],
            ['aq-career-detail-cv', 'career', 'detail', 'text', 'CV / Portfolyo linki', null, null, false, 1],
            ['aq-career-detail-experience', 'career', 'detail', 'textarea', 'Deneyimleriniz', null, null, true, 2],
            ['aq-career-detail-availability', 'career', 'detail', 'date', 'Uygunluk tarihiniz', null, null, false, 3],
            ['aq-career-detail-reference', 'career', 'detail', 'text', 'Referans (varsa)', null, null, false, 4],

            // ---- club / community (Kulüpler / Topluluklar) ----
            ['aq-club-preview-purpose', 'club', 'preview', 'textarea', 'Bu kulübe katılma amacınız', null, null, true, 1],
            ['aq-club-preview-before', 'club', 'preview', 'single_choice', 'Daha önce benzer bir kulüpte yer aldınız mı?', null, ['Evet', 'Hayır'], false, 2],
            ['aq-club-detail-motivation', 'club', 'detail', 'textarea', 'Motivasyon mektubu', null, null, true, 1],
            ['aq-club-detail-availability', 'club', 'detail', 'text', 'Uygun toplantı saatleriniz', null, null, false, 2],
            ['aq-club-detail-skills', 'club', 'detail', 'textarea', 'Yetenek / ilgi alanlarınız', null, null, false, 3],

            ['aq-community-preview-purpose', 'community', 'preview', 'textarea', 'Bu topluluğa katılma amacınız', null, null, true, 1],
            ['aq-community-preview-before', 'community', 'preview', 'single_choice', 'Daha önce benzer bir toplulukta yer aldınız mı?', null, ['Evet', 'Hayır'], false, 2],
            ['aq-community-detail-motivation', 'community', 'detail', 'textarea', 'Motivasyon mektubu', null, null, true, 1],
            ['aq-community-detail-availability', 'community', 'detail', 'text', 'Uygun toplantı saatleriniz', null, null, false, 2],

            // ---- event (Yaratıcı Kampüs etkinlikleri, öğrenci-önerili programlar) ----
            ['aq-event-preview-purpose', 'event', 'preview', 'single_choice', 'Etkinliğe katılma amacınız', null, ['Bilgi edinmek', 'Ağ kurmak', 'Eğlence', 'Zorunlu'], true, 1],
            ['aq-event-preview-role', 'event', 'preview', 'single_choice', 'Tercih ettiğiniz katılım tipi', null, ['Dinleyici', 'Katılımcı', 'Gönüllü'], false, 2],
            ['aq-event-detail-needs', 'event', 'detail', 'textarea', 'Beslenme / erişilebilirlik ihtiyacınız var mı?', null, null, false, 1],
            ['aq-event-detail-source', 'event', 'detail', 'text', 'Etkinlikten nasıl haberdar oldunuz?', null, null, false, 2],
        ];

        foreach ($rows as [$id, $targetType, $stage, $type, $label, $help, $options, $required, $sort]) {
            ApplicationQuestion::updateOrCreate(['id' => $id], [
                'target_type' => $targetType,
                'stage' => $stage,
                'type' => $type,
                'label' => $label,
                'help_text' => $help,
                'options' => $options,
                'required' => $required,
                'sort_order' => $sort,
                'active' => true,
            ]);
        }
    }
}
