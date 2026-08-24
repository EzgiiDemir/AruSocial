<?php

namespace Tests\Concerns;

use App\Models\Place;

trait CreatesPlaces
{
    protected function seedPlace(string $id = 'place-review-x'): Place
    {
        return Place::create([
            'id' => $id,
            'name' => 'Atelier',
            'category' => 'Studio',
            'lat' => 35.3396,
            'lng' => 33.3155,
            'description' => 'test place',
            'distance' => '10m',
            'density' => 'quiet',
            'street' => 'Test Cd.',
            'accessible' => true,
            'photos' => 1,
            'rating' => 4.5,
        ]);
    }
}
