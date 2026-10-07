<?php

namespace App\Services\Sis;

final class UnavailableSisProvider implements SisProvider
{
    public function isAvailable(): bool
    {
        return false;
    }

    public function profile(string $institutionalIdentity): array
    {
        throw new SisUnavailable('Live SIS access is not configured.');
    }

    public function registeredCourses(string $institutionalIdentity): array
    {
        throw new SisUnavailable('Live SIS access is not configured.');
    }

    public function timetable(string $institutionalIdentity, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        throw new SisUnavailable('Live SIS access is not configured.');
    }
}
