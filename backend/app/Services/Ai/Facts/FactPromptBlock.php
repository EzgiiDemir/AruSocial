<?php

namespace App\Services\Ai\Facts;

use App\Services\Ai\AppNavigation;
use App\Services\Ai\AskTrace;
use App\Services\Ai\SourceAuthority;

/**
 * The model-facing input for a planned question generated from facts: the
 * AnswerPlan and the SupportedFacts, nothing else factual. Raw tool rows and
 * the retrieved-pages block are not included — their facts were already
 * normalized — so what the model can state is bounded by what the system
 * established. Presentation (wording, order, tone) stays with the model.
 *
 * A document-backed fact carries its minimal supporting span, fenced as
 * untrusted content with the `(Kaynak: url)` marker the prompt budget uses
 * to decide which sources reached the model.
 */
final class FactPromptBlock
{
    public const BLOCK_ID = 'tool:facts';

    private const LABELS = [
        'current_opening_hours' => 'çalışma saatleri', 'is_open_now' => 'şu an açık mı', 'place_coordinates' => 'konum',
        'user_location' => 'öğrencinin konumu', 'route_distance_m' => 'yürüme mesafesi (metre)', 'route_duration_min' => 'yürüme süresi (dakika)',
        'program_language' => 'eğitim dili', 'required_documents' => 'gerekli belgeler', 'current_menu' => 'bugünkü menü',
        'current_events' => 'etkinlik', 'food_places' => 'yemek yeri', 'club_social_profile' => 'sosyal medya hesabı',
        'academic_date' => 'akademik takvim', 'programme_duration' => 'eğitim süresi', 'contact_email' => 'e-posta', 'contact_phone' => 'telefon',
    ];

    private const GUIDANCE = [
        AnswerPlan::INSUFFICIENT => 'Resmî kaynaklar bunu açıkça belirtmiyor. Bunu söyle; değer, liste ya da isim uydurma; ilgili birime danışmayı öner.',
        AnswerPlan::UNAVAILABLE => 'Kampüs verisinde bu bilgi yok. Bunu söyle; tahmin etme.',
        AnswerPlan::STALE => 'Elimizde yalnızca güncelliğini yitirmiş bilgi var. Bunu güncel bilgi gibi sunma; güncel olmadığını söyle.',
        AnswerPlan::CONTEXT_MISSING => 'Öğrencinin konumu bilinmediği için rota hesaplanamadı. Aşağıdaki doğrulanmış kısmı (örneğin gidilecek binanın adını) söyle, ardından konumunu paylaşırsa rotanın çıkarılabileceğini ekle; süre ya da mesafe verme.',
        AnswerPlan::NOT_APPLICABLE => 'Önceki adım tamamlanamadığı için bu yapılamadı. Bunu kısaca söyle.',
        AnswerPlan::CONFLICTING => 'Güvenilir kaynaklar farklı bilgi veriyor. Birini seçme; kaynakların çeliştiğini ve değerleri söyle, birimle teyit etmesini öner.',
        AnswerPlan::PARTIAL => 'Yalnızca aşağıdaki kısmı doğrulandı; eksik kısmı tahmin etme.',
        AnswerPlan::SUPPORTED => '',
    ];

    private const STATUS_LABELS = [
        AnswerPlan::SUPPORTED => 'DOĞRULANDI', AnswerPlan::PARTIAL => 'KISMEN DOĞRULANDI', AnswerPlan::INSUFFICIENT => 'YETERSİZ KANIT',
        AnswerPlan::UNAVAILABLE => 'BİLGİ YOK', AnswerPlan::STALE => 'GÜNCEL DEĞİL', AnswerPlan::CONTEXT_MISSING => 'KONUM GEREKLİ',
        AnswerPlan::NOT_APPLICABLE => 'YAPILAMADI', AnswerPlan::CONFLICTING => 'KAYNAKLAR ÇELİŞİYOR',
    ];

    /** @return array{text: string, sources: list<array<string, mixed>>} */
    public function build(FactResult $facts): array
    {
        $lines = ['YANIT PLANI VE DOĞRULANMIŞ BİLGİLER. Olgusal her ifaden yalnızca aşağıdaki [fact_…] bilgilerinden birine dayanmalı. '
            .'Genel bilgiden ekleme yapma, eksik değeri tahmin etme. Saat, tarih, sayı, bağlantı, e-posta, kişi, bina, sınav, program dili veya belge adı '
            .'yalnızca burada geçiyorsa yazılabilir. [fact_…] kimliklerini yanıtta gösterme. Bilgileri doğal, kısa ve öğrencinin dilinde anlat.'];
        foreach ($facts->plan->tasks as $task) {
            $guidance = self::GUIDANCE[$task['status']] ?? '';
            if ($task['status'] === AnswerPlan::CONFLICTING && $task['conflict_values'] !== []) {
                $guidance .= ' Değerler: '.implode(' / ', $task['conflict_values']).'.';
            }
            if (($task['absence'] ?? null) === AnswerPlan::NOT_FOUND_IN_CURRENT_DATA) {
                $guidance .= ' "Mevcut verilerde bulunamadı" de; "yoktur", "sunulmuyor" gibi kesin bir yokluk iddiasında bulunma.';
            }
            // A partial task's verified part is part of the answer: a missing
            // route must not erase the destination that IS known.
            if ($task['status'] !== AnswerPlan::SUPPORTED && $task['fact_ids'] !== []) {
                $guidance .= ' Doğrulanmış kısmı mutlaka belirt.';
            }
            $lines[] = 'Görev '.$task['task_id'].' ('.$task['task_type'].') — '.(self::STATUS_LABELS[$task['status']] ?? $task['status'])
                .($guidance !== '' ? ': '.$guidance : ':');
            foreach ($task['fact_ids'] as $id) {
                $fact = $facts->fact($id);
                if ($fact === null || $fact->redacted) {
                    continue;
                }
                $subject = isset($fact->subject['name']) && $fact->valueType !== 'location' ? $fact->subject['name'].' — ' : '';
                $label = self::LABELS[$fact->factType] ?? $fact->factType;
                if ($fact->factType === 'place_coordinates') {
                    // Said plainly, so a destination is never read as where the student is.
                    $label = in_array($task['task_type'], ['route', 'rank_by_distance'], true) ? 'gidilecek yer (varış noktası)' : 'bulunduğu bina';
                }
                $value = match (true) {
                    $fact->factType === 'is_open_now' => ($fact->value ? 'şu an AÇIK' : 'şu an KAPALI')
                        .' (kampüs saatiyle '.substr((string) ($fact->qualifiers['evaluated_at'] ?? ''), 11, 5).')',
                    // The building in the forms an answer uses, so a correct
                    // "Titan Building" is not mistaken for an invented name.
                    $fact->factType === 'place_coordinates' => ($fact->subject['name'] ?? '').' binası (Building: '.($fact->subject['name'] ?? '').' Building)',
                    default => $fact->display(),
                };
                $line = '  ['.$fact->id.'] '.$subject.$label.': '.$value;
                $doc = collect($fact->provenance)->first(fn ($p) => in_array($p['source_type'], ['official_web', 'official_pdf'], true) && $p['url']);
                if ($fact->span !== null && $doc !== null) {
                    $span = str_replace(['<UNTRUSTED_OFFICIAL_CONTENT>', '</UNTRUSTED_OFFICIAL_CONTENT>'], '', $fact->span);
                    $page = $doc['page'] !== null ? ', sayfa '.$doc['page'] : '';
                    $line .= "\n<UNTRUSTED_OFFICIAL_CONTENT>\n  dayanak: \"{$span}\" (Kaynak: {$doc['url']}{$page})\n</UNTRUSTED_OFFICIAL_CONTENT>";
                }
                $lines[] = $line;
            }
        }
        // The only app screens an answer may send the student to (ClaimVerifier rejects any other).
        $navigation = app(AppNavigation::class)->promptLine();
        if ($navigation !== '') {
            $lines[] = $navigation;
        }
        $text = implode("\n", $lines);
        $sources = $this->sources($facts);
        app(AskTrace::class)->record('generation_input', [
            'mode' => 'supported_facts', 'chars' => mb_strlen($text),
            'fact_ids' => array_map(fn (SupportedFact $f) => $f->id, $facts->facts()),
            'sources' => array_map(fn ($s) => ['title' => $s['title'], 'url' => $s['url'], 'fact_ids' => $s['fact_ids']], $sources),
        ]);

        return ['text' => $text, 'sources' => $sources];
    }

    /**
     * One citable source per distinct provenance source, each listing the
     * facts it backs — so the citation list can later be cut down to the
     * facts the answer actually used.
     *
     * @return list<array<string, mixed>>
     */
    private function sources(FactResult $facts): array
    {
        $out = [];
        foreach ($facts->facts() as $fact) {
            foreach ($fact->provenance as $p) {
                [$key, $source] = match (true) {
                    in_array($p['source_type'], ['official_web', 'official_pdf'], true) || ($p['url'] ?? null) => [(string) $p['url'], [
                        'type' => $p['source_type'] === 'official_pdf' || str_ends_with(strtolower((string) $p['url']), '.pdf') ? 'pdf' : 'web',
                        'title' => (string) ($p['title'] ?: $p['url']), 'url' => (string) $p['url'], 'id' => sha1((string) $p['url']),
                        'authority' => SourceAuthority::OFFICIAL_DOC,
                    ]],
                    $p['source_type'] === 'database' => [self::BLOCK_ID.':'.explode(':', (string) $p['source_id'])[0], [
                        'type' => 'campus', 'title' => self::tableTitle((string) $p['source_id']), 'url' => '', 'id' => self::BLOCK_ID,
                        'authority' => SourceAuthority::OPERATIONAL,
                    ]],
                    default => [null, null],   // routing and the request are computed, not cited
                };
                if ($key === null) {
                    continue;
                }
                $out[$key] ??= $source + ['visibility' => SourceAuthority::VISIBILITY_PUBLIC, 'freshness' => SourceAuthority::FRESHNESS_LIVE,
                    'updatedAt' => null, 'stale' => false, 'fact_ids' => []];
                $out[$key]['fact_ids'] = array_values(array_unique([...$out[$key]['fact_ids'], $fact->id]));
            }
        }

        return array_values($out);
    }

    private static function tableTitle(string $sourceId): string
    {
        return match (explode(':', $sourceId)[0]) {
            'services' => 'Kampüs hizmetleri',
            'places' => 'Kampüs yerleri',
            'food_venues', 'food_daily_menus' => 'Yemek yerleri',
            'events' => 'Etkinlikler',
            'clubs' => 'Kulüpler',
            'knowledge_facts' => 'Program bilgileri',
            default => 'Kampüs verisi',
        };
    }
}
