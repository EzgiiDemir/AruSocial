<?php

namespace App\Services;

use App\Models\AcademicStaff;

// Real "ilgili akademik personele yönlendirme" (docs/EKSIKLER.md aktivite/
// onay workflow §7): routes a student activity to the right approver by
// department, falling back to the faculty dean, then null (no automatic
// admin fallback beyond that — a real admin reviews unrouted activities
// manually rather than this silently picking an arbitrary approver).
class AcademicRoutingService
{
    /**
     * Department names as they appear in the real AcademicStaff directory
     * that a given category/department label should match. Multiple
     * aliases per canonical department because students will type
     * whatever they call their own department, not necessarily the exact
     * HR string.
     */
    private const DEPARTMENT_ALIASES = [
        'Yeni Medya ve İletişim' => ['yeni medya'],
        'Dijital Oyun Tasarımı' => ['dijital oyun'],
        'Görsel İletişim Tasarımı' => ['görsel iletişim'],
        'Film Tasarımı ve Yönetimi' => ['film tasarımı', 'film yönetimi'],
        'Film/Fotograf' => ['film/fotoğraf', 'fotoğraf', 'film-fotoğraf'],
        'Mimarlık' => ['mimarlık'],
        'İç Mimarlık ve Çevre Tasarımı' => ['iç mimarlık'],
        'Oyunculuk' => ['oyunculuk'],
        'Modern Dans' => ['modern dans', 'dans'],
        'Ses Sanatları Tasarımı' => ['ses sanatları'],
        'Plastik Sanatlar' => ['plastik sanatlar'],
        'Endüstriyel Tasarım' => ['endüstriyel tasarım'],
        'Kentsel Tasarım ve Peyzaj Mimarlığı' => ['kentsel tasarım', 'peyzaj mimarlığı'],
    ];

    // PHP's default mb_strtolower('İ') (Unicode case folding, no Turkish
    // locale) produces "i" + a combining dot-above mark, not a plain
    // ASCII "i" — silently breaking any substring match against a
    // hand-written lowercase alias like 'iletişim'. Real bug, found by
    // AcademicRoutingServiceTest actually failing against the seeded
    // "Görsel İletişim" data, not a hypothetical.
    private static function trLower(string $s): string
    {
        return mb_strtolower(strtr($s, ['İ' => 'i', 'I' => 'ı']));
    }

    public static function routeFor(?string $department, ?string $faculty): ?AcademicStaff
    {
        if ($department) {
            $normalized = self::trLower(trim($department));
            foreach (self::DEPARTMENT_ALIASES as $canonical => $aliases) {
                $matches = self::trLower($canonical) === $normalized
                    || collect($aliases)->contains(fn ($a) => str_contains($normalized, $a));
                if ($matches) {
                    $head = AcademicStaff::where('department', $canonical)
                        ->where('is_department_head', true)
                        ->first();
                    if ($head) {
                        return $head;
                    }
                }
            }
            // No alias matched, but maybe the department string is already
            // exactly what's in the directory (e.g. admin-entered).
            $exact = AcademicStaff::where('department', $department)
                ->where('is_department_head', true)
                ->first();
            if ($exact) {
                return $exact;
            }
        }

        if ($faculty) {
            $dean = AcademicStaff::where('faculty', $faculty)
                ->where('is_faculty_dean', true)
                ->first();
            if ($dean) {
                return $dean;
            }
        }

        return null;
    }
}
