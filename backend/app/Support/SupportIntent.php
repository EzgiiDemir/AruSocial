<?php

namespace App\Support;

use App\Models\ServiceItem;

/**
 * Recognises a student asking for help with how they are feeling, and answers
 * with the real support service instead of a catalogue lookup.
 *
 * A student wrote "psikolojik sorunum var" and AICAD replied "Bunu kayıtta net
 * eşleştiremedim. Denemek için bir yer adı yaz: The Garden, ARUCAD Dormitory…"
 * with directions buttons — because the degraded fallback treats every question
 * as a place lookup, and none of the agent's keywords covered distress. ARUCAD
 * has a Psikolojik Danışmanlık Merkezi the whole time.
 *
 * This path must work even when the AI provider is down, which is exactly when
 * that reply was produced, so it is plain deterministic code and its contact
 * details come from the ServiceItem row — never invented.
 */
class SupportIntent
{
    /** Someone describing how they feel, or asking for support. */
    private const WELLBEING = [
        'psikolojik', 'psikolog', 'psikiyat', 'depres', 'kaygı', 'kaygi',
        'anksiyete', 'panik atak', 'stres', 'bunalım', 'bunalim', 'tükendim',
        'tukendim', 'mutsuz', 'yalnız hissed', 'yalniz hissed', 'umutsuz',
        'moralim bozuk', 'içim daralıyor', 'icim daraliyor', 'ağlıyorum',
        'agliyorum', 'uyuyamıyorum', 'uyuyamiyorum', 'motivasyonum yok',
        'danışmanlık merkezi', 'terapi', 'ruh sağlığı', 'ruh sagligi',
        'psychological', 'psychologist', 'therapy', 'therapist', 'counsel',
        'depressed', 'depression', 'anxiety', 'anxious', 'panic attack',
        'stressed', 'burnout', 'burned out', 'lonely', 'hopeless',
        'mental health', 'can not sleep', 'cannot sleep',
    ];

    /** Language that means someone may be in danger right now. */
    private const CRISIS = [
        'intihar', 'kendime zarar', 'canıma kıy', 'canima kiy',
        'yaşamak istemiyorum', 'yasamak istemiyorum', 'ölmek istiyorum',
        'olmek istiyorum', 'suicide', 'kill myself', 'killing myself',
        'end my life', 'self harm', 'self-harm', 'hurt myself',
        'want to die', 'no reason to live',
    ];

    public function matches(string $query): bool
    {
        return $this->hit($query, self::WELLBEING) || $this->isCrisis($query);
    }

    public function isCrisis(string $query): bool
    {
        return $this->hit($query, self::CRISIS);
    }

    /**
     * @param  list<string>  $needles
     */
    private function hit(string $query, array $needles): bool
    {
        $q = mb_strtolower($query);
        foreach ($needles as $needle) {
            if (str_contains($q, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A warm, grounded reply naming the real service, or null when no support
     * service is configured (then the caller answers normally rather than
     * inventing one).
     */
    public function message(string $query): ?string
    {
        $service = $this->service();
        if ($service === null) {
            return null;
        }

        $english = QueryLanguage::detect($query) === 'en';
        $where = trim((string) $service->building);
        $contact = trim((string) $service->contact);
        $hours = trim((string) $service->hours);

        $details = [];
        if ($where !== '') {
            $details[] = $english ? "Building: {$where}" : "Bina: {$where}";
        }
        if ($contact !== '') {
            $details[] = $english ? "Contact: {$contact}" : "İletişim: {$contact}";
        }
        if ($hours !== '') {
            $details[] = $english ? "Hours: {$hours}" : "Çalışma şekli: {$hours}";
        }
        $tail = $details === [] ? '' : ' '.implode(' · ', $details);

        if ($this->isCrisis($query)) {
            return $english
                ? 'I am really sorry you are going through this, and I am glad you said '
                    ."something. Please reach a person now — ARUCAD's {$service->title} can "
                    .'talk with you, and if you are in immediate danger contact the emergency '
                    ."services or campus security straight away.{$tail}"
                : 'Bunu yaşadığın için gerçekten üzgünüm ve söylediğin için iyi ettin. '
                    ."Lütfen şimdi bir insana ulaş: ARUCAD {$service->title} seninle "
                    .'konuşabilir. Acil bir tehlike varsa vakit kaybetmeden acil servisi '
                    ."veya kampüs güvenliğini ara.{$tail}";
        }

        return $english
            ? "You are not alone in this, and asking is a good step. ARUCAD's "
                ."{$service->title} supports students with exactly this: {$service->description}"
                .$tail
            : "Bunu yaşayan tek kişi değilsin ve sormak iyi bir adım. ARUCAD'ın "
                ."{$service->title} birimi tam olarak bu konuda destek veriyor: "
                ."{$service->description}".$tail;
    }

    /** The configured wellbeing service, by category rather than by name. */
    public function service(): ?ServiceItem
    {
        return ServiceItem::query()->where('category', 'Wellbeing')->first()
            ?? ServiceItem::query()->where('title', 'like', '%Psikolojik%')->first();
    }
}
