<?php

namespace App\Services\Sis;

/** Vendor-neutral, read-only SIS boundary. Implementations never receive client-supplied student IDs. */
interface SisProvider
{
    public function isAvailable(): bool;

    /** @return array<string,mixed> */
    public function profile(string $institutionalIdentity): array;

    /** @return list<array<string,mixed>> */
    public function registeredCourses(string $institutionalIdentity): array;

    /** @return list<array<string,mixed>> */
    public function timetable(string $institutionalIdentity, \DateTimeInterface $from, \DateTimeInterface $to): array;
}
