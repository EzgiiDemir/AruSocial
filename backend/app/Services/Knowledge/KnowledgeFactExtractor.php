<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeDocument;
use App\Models\KnowledgeFact;
use App\Support\TextFold;

/**
 * Extracts programme facts from the one page structure that states them
 * reliably: the admissions sites' field block
 *
 *     "… Eğitim Dili İngilizce Eğitim Süresi 4 Yıl Zorunlu İngilizce Hazırlık …"
 *
 * found on 30+ programme pages (aday., kibrisaday.). The main site states the
 * same in FAQ prose deep in the page, and at least one FAQ names the wrong
 * department, so prose is deliberately NOT parsed: a fact is only taken from
 * the label-value-label field form. Each fact keeps its source URL and the
 * exact passage. A page that no longer has the block loses its facts.
 */
final class KnowledgeFactExtractor
{
    private const FIELD_BLOCK = '/Eğitim Dili\s*:?\s*(İngilizce|Türkçe)\s+Eğitim Süresi\s*:?\s*([0-9][0-9\-–]*\s*(?:Yıl|yıl|Yarıyıl|yarıyıl))/u';

    private const TURKISH_TITLE = '/^(.+?)\s*\(Turkish\)/u';

    /** @return int facts written for this document */
    public function extract(KnowledgeDocument $document): int
    {
        if (! str_contains((string) $document->url, '/rt-program/')) {
            return 0;
        }
        $text = (string) ($document->content_clean ?: $document->content);

        // The English admissions site has no field block, but marks a
        // Turkish-taught programme explicitly in its title: "Acting
        // (Turkish) – Prospective ARUCAD". That marker is a statement, so it
        // is a fact; the absence of a marker is NOT taken to mean English.
        if (preg_match(self::TURKISH_TITLE, (string) $document->title, $t) === 1) {
            KnowledgeFact::query()->updateOrCreate(
                ['knowledge_document_id' => $document->id, 'attribute' => KnowledgeFact::LANGUAGE],
                [
                    'subject_type' => KnowledgeFact::SUBJECT_PROGRAMME,
                    'subject' => trim($t[1]),
                    'subject_folded' => TextFold::fold(trim($t[1])),
                    'value' => 'Türkçe',
                    'source_url' => (string) $document->url,
                    'source_passage' => (string) $document->title,
                    'verified_at' => $document->fetched_at ?? now(),
                ],
            );

            return 1;
        }

        if (preg_match(self::FIELD_BLOCK, $text, $m, PREG_OFFSET_CAPTURE) !== 1) {
            KnowledgeFact::query()->where('knowledge_document_id', $document->id)->get()->each->delete();

            return 0;
        }

        $subject = $this->subject((string) $document->title);
        if ($subject === '') {
            return 0;
        }
        $passage = trim(mb_substr($text, max(0, mb_strlen(substr($text, 0, $m[0][1])) - 20), mb_strlen($m[0][0]) + 40));
        $facts = [KnowledgeFact::LANGUAGE => $m[1][0], KnowledgeFact::DURATION => trim($m[2][0])];
        foreach ($facts as $attribute => $value) {
            KnowledgeFact::query()->updateOrCreate(
                ['knowledge_document_id' => $document->id, 'attribute' => $attribute],
                [
                    'subject_type' => KnowledgeFact::SUBJECT_PROGRAMME,
                    'subject' => $subject,
                    'subject_folded' => TextFold::fold($subject),
                    'value' => $value,
                    'source_url' => (string) $document->url,
                    'source_passage' => $passage,
                    'verified_at' => $document->fetched_at ?? now(),
                ],
            );
        }

        return count($facts);
    }

    /** "Görsel İletişim Tasarımı – Aday – ARUCAD" → "Görsel İletişim Tasarımı" */
    private function subject(string $title): string
    {
        $parts = preg_split('/\s+[–\-|]\s+/u', $title) ?: [$title];

        return trim($parts[0]);
    }
}
