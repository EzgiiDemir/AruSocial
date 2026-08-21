<?php

namespace App\Services;

use App\Models\User;
use App\Models\XpTransaction;
use Illuminate\Support\Str;

// Every real XP grant goes through here (docs/EKSIKLER.md harita/check-in/
// XP "xp_transactions mantığı") — increments the fast-read `users.xp`
// counter *and* records a permanent, auditable row explaining why.
class XpLedger
{
    public static function grant(User $user, int $amount, string $reason, string $sourceType, ?string $sourceId = null): void
    {
        $user->increment('xp', $amount);
        XpTransaction::create([
            'id' => 'xp-'.Str::uuid(),
            'user_id' => $user->id,
            'amount' => $amount,
            'reason' => $reason,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'created_at' => now(),
        ]);
    }
}
