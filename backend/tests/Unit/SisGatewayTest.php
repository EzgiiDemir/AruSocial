<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Sis\SisGateway;
use App\Services\Sis\SisProvider;
use Tests\TestCase;

class SisGatewayTest extends TestCase
{
    public function test_identity_always_comes_from_authenticated_user(): void
    {
        $probe = (object) ['identity' => null];
        $provider = new class($probe) implements SisProvider
        {
            public function __construct(private object $probe) {}

            public function isAvailable(): bool
            {
                return true;
            }

            public function profile(string $institutionalIdentity): array
            {
                return [];
            }

            public function registeredCourses(string $institutionalIdentity): array
            {
                return [];
            }

            public function timetable(string $institutionalIdentity, \DateTimeInterface $from, \DateTimeInterface $to): array
            {
                $this->probe->identity = $institutionalIdentity;

                return [];
            }
        };
        $user = new User;
        $user->email = 'owner@arucad.edu.tr';

        (new SisGateway($provider))->timetableFor($user, new \DateTimeImmutable('2026-09-22'), new \DateTimeImmutable('2026-09-22'));

        $this->assertSame('owner@arucad.edu.tr', $probe->identity);
    }
}
