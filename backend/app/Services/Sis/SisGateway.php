<?php

namespace App\Services\Sis;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/** Authenticated-user scoped SIS facade; there is deliberately no student-id parameter. */
final class SisGateway
{
    public function __construct(private readonly SisProvider $provider) {}

    public function isAvailable(): bool
    {
        return $this->provider->isAvailable();
    }

    /** @return list<array<string,mixed>> */
    public function timetableFor(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $identity = $this->identityFor($user);
        if (! $this->provider->isAvailable()) {
            throw new SisUnavailable('Live SIS/timetable access is not currently available.');
        }

        Log::info('sis.timetable.read', [
            'app_user_id' => $user->getKey(),
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ]);

        return $this->provider->timetable($identity, $from, $to);
    }

    private function identityFor(User $user): string
    {
        // The mapping is server-owned. A future provider may replace this
        // with an immutable Entra object ID or an approved mapping table.
        $field = (string) config('services.sis.identity_field', 'email');
        $identity = trim((string) $user->getAttribute($field));
        if ($identity === '') {
            throw new SisUnavailable('The authenticated account has no SIS identity mapping.');
        }

        return $identity;
    }
}
