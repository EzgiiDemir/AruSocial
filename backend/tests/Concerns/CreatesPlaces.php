<?php

namespace Tests\Concerns;

use App\Models\Place;

trait CreatesPlaces
{
    protected function seedPlace(string $id = 'place-review-x'): Place
    {
        return Place::create([
            'id' => $id,
            'name' => 'Carpentry Studio',
            'category' => 'Workshop',
            'lat' => 35.337502,
            'lng' => 33.321226,
            'description' => 'test place',
            'distance' => '10m',
            'density' => 'quiet',
            'street' => 'Şair Nedim Sokak',
            'accessible' => true,
            'photos' => 1,
            'rating' => 4.5,
        ]);
    }
}
