<?php

namespace App\Services;

use App\Models\User;

// Real, server-side enforcement — the Flutter client already had its own
// text blocklist (lib/core/services/content_moderation.dart), but that
// only ever protected a well-behaved client; nothing stopped a modified
// client (or a direct API call) from posting anything. This is the same
// idea, checked again on the server where it can't be bypassed, with a
// real consequence: each violation is a strike, and enough strikes is a
// real account ban (see docs/EKSIKLER.md §16).
class ModerationService
{
    private const BAN_AFTER_STRIKES = 3;

    // Deliberately short and representative, mirroring the Dart list —
    // a real deployment would use a maintained, much larger list (and
    // ideally a real moderation API) rather than a hardcoded list.
    private const BLOCKED_TERMS = [
        'orospu', 'piç', 'yavşak', 'ahmak', 'salak', 'aptal', 'nazi', 'terörist',
    ];

    /**
     * Checks $text for blocked content. If it's clean, returns null. If
     * it's flagged, records a real strike against $user (banning them once
     * they cross the threshold) and returns the reason to show the caller.
     */
    public static function checkText(User $user, string $text): ?string
    {
        $normalized = mb_strtolower($text);
        foreach (self::BLOCKED_TERMS as $term) {
            if (str_contains($normalized, $term)) {
                self::recordStrike($user, "metin: \"$term\"");

                return "İçerik uygunsuz olabilecek bir ifade içeriyor (\"$term\"). Lütfen düzenleyip tekrar dene.";
            }
        }

        return null;
    }

    /**
     * Same real strike/ban mechanism as checkText(), for the client-side
     * vision-moderation check (`ImageModerationService` in Flutter) —
     * previously a flagged photo only ever rejected that one upload and
     * never counted toward the ban threshold, unlike flagged text
     * (docs/EKSIKLER.md §26). $reason is the moderation-API category that
     * was hit (e.g. "sexual", "violence").
     */
    public static function recordImageStrike(User $user, string $reason): void
    {
        self::recordStrike($user, "görsel: $reason");
    }

    private static function recordStrike(User $user, string $reason): void
    {
        $user->increment('strikes');
        $user->refresh();
        AuditLogger::log('system', 'moderation_strike', 'user', "{$user->name} ({$user->strikes}/".self::BAN_AFTER_STRIKES."): $reason");

        if ($user->strikes >= self::BAN_AFTER_STRIKES && ! $user->isBanned()) {
            $user->update(['banned_at' => now()]);
            AuditLogger::log('system', 'ban', 'user', "{$user->name} otomatik olarak yasaklandı (".self::BAN_AFTER_STRIKES." ihlal)");
        }
    }
}
