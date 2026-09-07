<?php

namespace Tests\Feature;

use Database\Seeders\CampusCatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampusCatalogPlacesTest extends TestCase
{
    use RefreshDatabase;

    public function test_catalog_seeder_exposes_the_nineteen_real_campus_pois(): void
    {
        $this->seed(CampusCatalogSeeder::class);
        $this->actingAsUser();

        $names = collect($this->getJson('/api/v1/places')->assertOk()->json('data'))
            ->pluck('name')
            ->unique()
            ->values();

        foreach ([
            'Rodin',
            'Falling Man',
            'Titan',
            'Eve',
            'Daniele',
            'Eternal Spring',
            'Meditation',
            'Minotaur',
            'Eternal Idol',
            'The Kiss',
            'The Garden',
            'Carpentry Studio',
            'Arkin Rodin Collection Gallery',
            'ARUCAD Dormitory',
            'Nicosia Bandabuliya Campus',
            'ARUCAD Art Space',
            'Age of Bronze',
            'Art Rooms',
            'Iris (Atelier Building)',
        ] as $name) {
            $this->assertTrue($names->contains($name), "missing place: {$name}");
        }

        $rodin = collect($this->getJson('/api/v1/places')->json('data'))
            ->firstWhere('name', 'Rodin');
        $this->assertNotNull($rodin);
        $this->assertEqualsWithDelta(35.337305, $rodin['lat'], 0.000001);
        $this->assertEqualsWithDelta(33.321303, $rodin['lng'], 0.000001);
        $this->assertStringContainsString('Rodin', $rodin['description']);
        $this->assertStringContainsString('vista_export/Main', $rodin['tourUrl']);

        $bandabuliya = collect($this->getJson('/api/v1/places')->json('data'))
            ->firstWhere('name', 'Nicosia Bandabuliya Campus');
        $this->assertStringContainsString('vista_export/Bandabuliya', $bandabuliya['tourUrl']);

        $iris = collect($this->getJson('/api/v1/places')->json('data'))
            ->firstWhere('name', 'Iris (Atelier Building)');
        $this->assertStringContainsString('vista_export/Atelier', $iris['tourUrl']);
    }

    public function test_catalog_seeder_exposes_help_services_sports_and_clubs(): void
    {
        $this->seed(CampusCatalogSeeder::class);
        $this->actingAsUser();

        $serviceIds = collect($this->getJson('/api/v1/services')->assertOk()->json('data'))->pluck('id');
        foreach (['student-affairs', 'academic-advising', 'pdr', 'career', 'library', 'international', 'dormitory', 'it', 'accessibility', 'lost-found'] as $id) {
            $this->assertTrue($serviceIds->contains($id), "missing service: {$id}");
        }
        $pdr = collect($this->getJson('/api/v1/services')->json('data'))->firstWhere('id', 'pdr');
        $this->assertSame('Minotaur', $pdr['building']);
        $this->assertNotEmpty($pdr['contactPerson']);

        $clubIds = collect($this->getJson('/api/v1/clubs')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($clubIds->contains('club-photography'));
        $this->assertTrue($clubIds->contains('club-charity'));

        $sportIds = collect($this->getJson('/api/v1/sports')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($sportIds->contains('sport-basketball'));

        $careerIds = collect($this->getJson('/api/v1/career/opportunities')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($careerIds->contains('career-internship-studio'));
        $this->assertTrue($careerIds->contains('career-job-alumni'));
    }
}
