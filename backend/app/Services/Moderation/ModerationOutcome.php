<?php

namespace App\Services\Moderation;

use App\Models\ModerationEvent;
use Illuminate\Support\Carbon;

/**
 * What a controller should do with a submission, and what to tell the user.
 *
 * The client needs to tell four situations apart — content rejected, user
 * warned, user banned, moderation temporarily unavailable — so each gets its
 * own error code. Category names and scores stay server-side: telling a user
 * exactly which threshold they missed is a recipe for probing around it.
 */
final class ModerationOutcome
{
    public const ALLOWED = 'allowed';

    public const WARNED = 'warned';

    public const REVIEW = 'review';

    public const REJECTED = 'rejected';

    public const BANNED = 'banned';

    public const UNAVAILABLE = 'unavailable';

    /**
     * The post is published and no strike is recorded — the author is shown
     * support contacts. Separate from REVIEW because the client must not
     * render this as "your content is being checked": someone who has just
     * said they want to hurt themselves needs a different sentence.
     */
    public const SUPPORT = 'support';

    /** @param list<string> $categories */
    private function __construct(
        public readonly string $status,
        public readonly array $categories = [],
        public readonly ?int $strike = null,
        public readonly ?string $penalty = null,
        public readonly ?Carbon $bannedUntil = null,
        public readonly ?string $eventId = null,
    ) {}

    public static function allowed(): self
    {
        return new self(self::ALLOWED);
    }

    /** @param list<string> $categories */
    public static function allowedWithWarning(array $categories): self
    {
        return new self(self::WARNED, $categories);
    }

    public static function allowedWithReview(): self
    {
        return new self(self::REVIEW);
    }

    public static function support(): self
    {
        return new self(self::SUPPORT, ['self_harm']);
    }

    /**
     * Media arrived but no vision model looked at it.
     *
     * Distinct from a text outage because there is no local fallback for
     * pixels: the offline engine reads words. Publishing here would mean
     * publishing genuinely uninspected images.
     */
    public static function mediaUninspected(): self
    {
        return new self(self::UNAVAILABLE, ['media_uninspected']);
    }

    /** @param list<string> $categories */
    public static function rejected(array $categories, int $strike, string $action, ?Carbon $bannedUntil, string $eventId): self
    {
        return new self(self::REJECTED, $categories, $strike, $action, $bannedUntil, $eventId);
    }

    public static function banned(?Carbon $until): self
    {
        return new self(self::BANNED, bannedUntil: $until);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE);
    }

    public static function fromRepeat(ModerationEvent $event): self
    {
        return match ($event->action) {
            ModerationEvent::ACTION_REJECTED => new self(
                self::REJECTED, $event->categories ?? [], $event->strike_number,
                $event->penalty, $event->banned_until, $event->id,
            ),
            ModerationEvent::ACTION_REVIEW => new self(self::REVIEW),
            ModerationEvent::ACTION_WARNED => new self(self::WARNED, $event->categories ?? []),
            default => new self(self::ALLOWED),
        };
    }

    /** True when the content may be saved and shown. */
    public function isPublishable(): bool
    {
        return in_array($this->status, [self::ALLOWED, self::WARNED, self::REVIEW, self::SUPPORT], true);
    }

    /** Stable machine code for the client to branch on. */
    public function errorCode(): string
    {
        return match ($this->status) {
            self::REJECTED => 'CONTENT_BLOCKED',
            self::BANNED => 'ACCOUNT_SUSPENDED',
            self::UNAVAILABLE => 'MODERATION_UNAVAILABLE',
            default => 'OK',
        };
    }

    /** User-facing Turkish message. Deliberately non-specific about scores. */
    public function message(): string
    {
        return match ($this->status) {
            self::REJECTED => $this->rejectionMessage(),
            self::BANNED => $this->bannedUntil !== null
                ? 'Hesabın geçici olarak askıya alındı. Tekrar paylaşabileceğin zaman: '
                    .$this->bannedUntil->timezone(config('app.timezone'))->format('d.m.Y H:i').'.'
                : 'Hesabın topluluk kurallarını ihlal nedeniyle askıya alındı.',
            self::UNAVAILABLE => in_array('media_uninspected', $this->categories, true)
                ? 'Görsel ve video kontrolü şu anda yapılamıyor, bu yüzden dosyan '
                    .'yayınlanmadı ve incelemeye alındı. Kontrol edilmeden hiçbir '
                    .'görsel akışta gösterilmez.'
                : 'İçerik kontrolü şu anda yapılamıyor. Lütfen birazdan tekrar dene.',
            // Not a penalty and not an error — the post went through. This
            // is the app noticing and offering a way to talk to someone.
            self::SUPPORT => 'Paylaşımın yayınlandı. Zor bir dönemden geçiyorsan yalnız '
                .'değilsin: ARUCAD Psikolojik Danışmanlık Birimi (psikolojik.danismanlik@arucad.edu.tr) '
                .'sana destek olabilir. Acil durumda 112’yi arayabilirsin.',
            default => '',
        };
    }

    /**
     * Naming the kind of problem ("nefret söylemi") helps someone fix an
     * honest mistake. Scores and thresholds stay server-side — those are
     * what would let somebody tune content to slip under the bar.
     */
    private function categoryLabel(): ?string
    {
        foreach ($this->categories as $category) {
            $label = match (true) {
                str_starts_with($category, 'hate'), $category === 'HATE' => 'nefret söylemi',
                str_starts_with($category, 'harassment'), $category === 'HAR' => 'taciz veya hakaret',
                str_starts_with($category, 'sexual/minors'), $category === 'CSA', $category === 'MINOR' => 'çocuk güvenliği',
                // "Çıplaklık" is named explicitly: the provider files nude
                // photos under `sexual`, and someone told only "cinsel
                // içerik" often does not realise their holiday photo was
                // rejected for nudity and simply tries again.
                str_starts_with($category, 'sexual'), $category === 'SEX' => 'çıplaklık veya cinsel içerik',
                str_starts_with($category, 'violence/graphic'), $category === 'GORE' => 'kan veya grafik şiddet',
                str_starts_with($category, 'violence'), $category === 'VIO' => 'şiddet',
                str_starts_with($category, 'illicit'), $category === 'CRIME', $category === 'DRUG' => 'yasadışı faaliyet',
                $category === 'THR' => 'tehdit',
                $category === 'PROF' => 'küfür veya saldırgan dil',
                $category === 'POL' => 'siyasi içerik',
                $category === 'SPAM' => 'spam',
                $category === 'PRIV' => 'mahremiyet ihlali',
                default => null,
            };
            if ($label !== null) {
                return $label;
            }
        }

        return null;
    }

    private function rejectionMessage(): string
    {
        $label = $this->categoryLabel();
        $base = $label !== null
            ? 'Bu içerik topluluk kurallarına aykırı '.$label.' içerdiği için paylaşılmadı.'
            : 'Bu içerik topluluk kurallarına aykırı olduğu için paylaşılmadı.';

        if (in_array('self-harm', $this->categories, true)
            || in_array('self-harm/intent', $this->categories, true)
            || in_array('SELF', $this->categories, true)) {
            return 'Bu içerik yayınlanamaz. Lütfen yalnız kalma; bir yakınına ulaş '
                .'veya 112 / yerel kriz hattından destek al.';
        }

        if ($this->bannedUntil !== null) {
            return $base.' Tekrarlanan ihlal nedeniyle hesabın '
                .$this->bannedUntil->timezone(config('app.timezone'))->format('d.m.Y H:i')
                .' tarihine kadar askıya alındı. Askı süresi bittiğinde hesabın '
                .'otomatik olarak açılır; ihlal devam ederse süre uzar.';
        }

        // Someone who has just had a post refused is the one person certain
        // to be reading this, so it is where the consequence of doing it
        // again actually lands. Saying "warning 1/3" alone leaves them to
        // guess what happens at 4.
        return $base.' Uyarı '.$this->strike.'/3. '
            .'Lütfen paylaşacağın görsel ve videolara dikkat et: 3 uyarıdan sonra '
            .'hesabın geçici olarak askıya alınır (4. ihlal 24 saat, 5. ihlal 3 gün, '
            .'6. ihlal 7 gün).';
    }

    /** Structured payload for the API error envelope. */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'strike' => $this->strike,
            'penalty' => $this->penalty,
            'bannedUntil' => $this->bannedUntil?->toIso8601String(),
        ], fn ($v) => $v !== null);
    }
}
