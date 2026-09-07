<?php

namespace App\Support;

// The real ARUCAD (Arkın Üniversitesi Yaratıcı Sanatlar ve Tasarım /
// Arkin University of Creative Arts and Design) faculty/department
// structure — sourced from arucad.edu.tr, not invented. This is the single
// place that structure is spelled out; StaffProfile seed data, and any
// admin-facing category dropdown, should reference these constants rather
// than retyping faculty/department strings a second time, so they can't
// drift apart. Mirrored on the Dart side by
// frontend/lib/core/config/campus_taxonomy.dart — keep both in parity.
class CampusTaxonomy
{
    public const FACULTIES = [
        'Faculty of Arts' => [
            'Archaeology',
            'Film Design and Management',
            'Photography',
            'Fine Arts',
            'Ceramics',
            'Textile and Fashion Design',
        ],
        'Faculty of Design' => [
            'Industrial Design',
            'Interior Architecture and Environmental Design',
            'Urban Design and Landscape Architecture',
            'Architecture',
        ],
        'Faculty of Communication' => [
            'Visual Communication Design',
            'New Media and Communication',
            'Digital Game Design',
        ],
        'Faculty of Music and Performing Arts' => [
            'Modern Dance',
            'Acting',
            'Sound Design',
        ],
    ];

    // Non-academic departments — offices/units, not under a faculty.
    // Matches the non-academic StaffProfile rows already seeded.
    public const NON_ACADEMIC_DEPARTMENTS = [
        'Student Affairs',
        'Sports',
        'Library',
        'Career',
        'IT',
        'Health',
        'International',
        'Security',
        'Administration',
        'Accommodation',
        'Transport',
        'Counseling',
        'Rectorate',
    ];

    public static function departments(): array
    {
        return array_merge(self::NON_ACADEMIC_DEPARTMENTS, ...array_values(self::FACULTIES));
    }

    public static function facultyOf(string $department): ?string
    {
        foreach (self::FACULTIES as $faculty => $departments) {
            if (in_array($department, $departments, true)) {
                return $faculty;
            }
        }

        return null;
    }
}
