<?php

namespace App\Services\Moderation;

use App\Models\ModerationEvent;
use Illuminate\Support\Carbon;

/**
 * What a controller should do with a submission, and what to tell the user.
 *
 * The client needs to tell five situations apart — content rejected, user
 * warned, posting restricted, account suspended, moderation temporarily
 * unavailable — so each gets its own error code. Category names and scores stay server-side: telling a user
 * exactly which threshold they missed is a recipe for probing around it.
 */
final class ModerationOutcome
{
    public const ALLOWED = 'allowed';

    public const WARNED = 'warned';

    public const REVIEW = 'review';

    public const REJECTED = 'rejected';

    public const BANNED = 'banned';

    /**
     * The account may not submit content for a while, but is otherwise
     * working — the 3-point rung of the ladder. Separate from BANNED
     * because a student who can still read the feed and their messages
     * must not be told their account is suspended.
     */
    public const RESTRICTED = 'restricted';

    public const UNAVAILABLE = 'unavailable';

    /**
     * The post is held and no strike is recorded — the author is shown
     * support contacts. Separate from REVIEW because the client must not
     * render this as "your content is being checked": someone who has just
     * said they want to hurt themselves needs a different sentence.
     */
    public const SUPPORT = 'support';

    /** @param list<string> $categories */
    private function __construct(
        public readonly string $status,
        public readonly array $categories = [],
        /** Points this violation cost, null when it cost nothing. */
        public readonly ?int $points = null,
        public readonly ?string $penalty = null,
        /** End of whichever lock this produced — suspension or restriction. */
        public readonly ?Carbon $until = null,
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

    /**
     * The semantic layer was unsure: held for a moderator, not refused.
     *
     * Carries the categories so the review queue can show *what* it was
     * unsure about, and the event id so the author can appeal a hold the
     * same way they can appeal a removal. No strike — the system is
     * saying it does not know, and nobody should be sanctioned for the
     * model's uncertainty.
     *
     * @param  list<string>  $categories
     */
    public static function heldForReview(array $categories, ?string $eventId = null): self
    {
        return new self(self::REVIEW, $categories, eventId: $eventId);
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
    public static function rejected(array $categories, ?int $points, ?string $action, ?Carbon $until, string $eventId): self
    {
        return new self(self::REJECTED, $categories, $points, $action, $until, $eventId);
    }

    public static function banned(?Carbon $until): self
    {
        return new self(self::BANNED, until: $until);
    }

    /** Serving a posting restriction: nothing new may be submitted. */
    public static function postingRestricted(?Carbon $until): self
    {
        return new self(self::RESTRICTED, until: $until);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE);
    }

    public static function fromRepeat(ModerationEvent $event): self
    {
        return match ($event->action) {
            ModerationEvent::ACTION_REJECTED => new self(
                self::REJECTED, $event->categories ?? [], $event->points,
                $event->penalty, $event->banned_until, $event->id,
            ),
            // A support decision must replay as support. Re-posting the
            // same words is what someone in distress does, and answering
            // the second attempt with a server error would withdraw the
            // offer of help at the moment it was repeated.
            ModerationEvent::ACTION_REVIEW => match ($event->decided_by) {
                'self_harm' => self::support(),
                'unavailable' => new self(self::UNAVAILABLE),
                default => new self(self::REVIEW),
            },
            ModerationEvent::ACTION_WARNED => new self(self::WARNED, $event->categories ?? []),
            default => new self(self::ALLOWED),
        };
    }

    /** True only after a positive, completed moderation decision. */
    public function isPublishable(): bool
    {
        return in_array($this->status, [self::ALLOWED, self::WARNED, self::SUPPORT], true);
    }

    /** Stable machine code for the client to branch on. */
    public function errorCode(): string
    {
        return match ($this->status) {
            self::REJECTED => 'CONTENT_BLOCKED',
            self::BANNED => 'ACCOUNT_SUSPENDED',
            self::RESTRICTED => 'POSTING_RESTRICTED',
            self::UNAVAILABLE => 'MODERATION_UNAVAILABLE',
            self::REVIEW, self::SUPPORT => 'MODERATION_PENDING',
            default => 'OK',
        };
    }

    /** User-facing Turkish message. Deliberately non-specific about scores. */
    public function message(): string
    {
        return match ($this->status) {
            self::REJECTED => $this->rejectionMessage(),
            self::BANNED => $this->until !== null
                ? 'Hesabın geçici olarak askıya alındı. Tekrar paylaşabileceğin zaman: '
                    .$this->formattedUntil().'.'
                : 'Hesabın topluluk kurallarını ihlal nedeniyle askıya alındı.',
            self::RESTRICTED => 'Topluluk kurallarını ihlal ettiğin için paylaşım yapman '
                .($this->until !== null ? $this->formattedUntil().' tarihine kadar ' : 'geçici olarak ')
                .'kısıtlandı. Uygulamanın geri kalanını kullanmaya devam edebilirsin: '
                .'akışı okuyabilir, mesajlarını görebilir ve randevularına erişebilirsin.',
            self::UNAVAILABLE => in_array('media_uninspected', $this->categories, true)
                ? 'Görsel ve video kontrolü şu anda yapılamıyor, bu yüzden dosyan '
                    .'yayınlanmadı ve incelemeye alındı. Kontrol edilmeden hiçbir '
                    .'görsel akışta gösterilmez.'
                : 'İçerik kontrolü şu anda yapılamıyor. Lütfen birazdan tekrar dene.',
            self::SUPPORT => 'Paylaşımın güvenlik incelemesine alındı. Zor bir dönemden geçiyorsan yalnız '
                .'değilsin: ARUCAD Psikolojik Danışmanlık Birimi (psikolojik.danismanlik@arucad.edu.tr) '
                .'sana destek olabilir. Acil durumda 112’yi arayabilirsin.',

            // Held, not refused — and the difference has to be audible.
            //
            // This fell through to an empty string, so a student whose
            // post was held saw a bare 400 with no explanation, which
            // reads as a broken app rather than a decision. Content held
            // because the system was unsure is the one case where saying
            // so plainly costs nothing and silence costs trust.
            self::REVIEW => 'Paylaşımın yayınlanmadan önce bir moderatör tarafından '
                .'kontrol edilecek. Bu bir ihlal tespiti değil: sistem emin olamadı, '
                .'bu yüzden kararı bir insan verecek. Onaylanırsa paylaşımın '
                .'yayınlanır ve hesabında hiçbir iz kalmaz.',

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

        // Someone who has just had a post refused is the one person
        // certain to be reading this, so it is where the consequence of
        // doing it again actually lands.
        //
        // What is said, and what is deliberately not: the points charged
        // and what they cost, never the category scores or thresholds.
        // A student should be able to understand and appeal the decision;
        // nobody should be able to tune content to slip under the bar.
        $consequence = match ($this->penalty) {
            'posting_restriction' => $this->until !== null
                ? ' Bu nedenle '.$this->formattedUntil().' tarihine kadar paylaşım yapamazsın; '
                    .'uygulamanın geri kalanı açık kalır.'
                : ' Bu nedenle paylaşım hakkın geçici olarak kısıtlandı.',
            'temporary_suspension' => $this->until !== null
                ? ' Bu nedenle hesabın '.$this->formattedUntil().' tarihine kadar askıya alındı. '
                    .'Süre dolduğunda hesabın otomatik olarak açılır.'
                : ' Bu nedenle hesabın geçici olarak askıya alındı.',
            'warning' => ' Bu bir uyarıdır; hesabına başka bir kısıtlama uygulanmadı.',
            default => '',
        };

        if ($this->points === null || $this->points <= 0) {
            return $base.$consequence;
        }

        return $base.' İhlal puanı: +'.$this->points.'.'.$consequence
            .' Puanlar süreyle birlikte düşer; ağır ihlaller daha çok puan getirir.';
    }

    /** The lock's end, in the app's own timezone. */
    private function formattedUntil(): string
    {
        return (string) $this->until?->timezone(config('app.timezone'))->format('d.m.Y H:i');
    }

    /** Structured payload for the API error envelope. */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'points' => $this->points,
            'penalty' => $this->penalty,
            // One key for both locks. Which one it is, is already in
            // `penalty` and in the error code; a client that only wants to
            // show "until when" should not have to know the difference.
            'until' => $this->until?->toIso8601String(),
        ], fn ($v) => $v !== null);
    }
}
