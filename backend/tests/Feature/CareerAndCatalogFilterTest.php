<?php

namespace Tests\Feature;

use App\Models\CareerOpportunity;
use App\Models\CareerProfile;
use App\Models\Event;
use App\Models\Place;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CareerAndCatalogFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_student_can_list_published_opportunities_paginated(): void
    {
        CareerOpportunity::create([
            'id' => 'c1', 'title' => 'Staj', 'kind' => 'internship',
            'organization' => 'ARUCAD', 'published' => true, 'created_at' => now(),
        ]);
        CareerOpportunity::create([
            'id' => 'c2', 'title' => 'Gizli', 'kind' => 'job',
            'organization' => 'X', 'published' => false, 'created_at' => now(),
        ]);
        $this->actingAsUser();

        $res = $this->getJson('/api/v1/career/opportunities')->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertSame('c1', $res->json('data.0.id'));
        $this->assertArrayHasKey('pagination', $res->json('meta'));
    }

    public function test_student_can_read_and_update_own_career_profile(): void
    {
        $me = $this->actingAsUser();

        $empty = $this->getJson('/api/v1/me/career-profile')->assertOk()->json('data');
        $this->assertNull($empty['headline']);
        $this->assertFalse($empty['lookingForInternships']);

        $updated = $this->postJson('/api/v1/me/career-profile', [
            'headline' => 'Tasarım öğrencisi',
            'cvUrl' => 'https://example.com/cv.pdf',
            'lookingForInternships' => true,
        ])->assertOk()->json('data');

        $this->assertSame('Tasarım öğrencisi', $updated['headline']);
        $this->assertTrue($updated['lookingForInternships']);
        $this->assertDatabaseHas('career_profiles', [
            'user_id' => $me->id, 'headline' => 'Tasarım öğrencisi',
        ]);
    }

    public function test_career_profile_is_isolated(): void
    {
        $other = User::create(['name' => 'Other', 'email' => 'other@arucad.edu.tr', 'password' => bcrypt('x')]);
        CareerProfile::create([
            'user_id' => $other->id, 'headline' => 'Secret', 'updated_at' => now(),
        ]);
        $this->actingAsUser();

        $data = $this->getJson('/api/v1/me/career-profile')->json('data');
        $this->assertNull($data['headline']);
    }

    public function test_career_staff_can_upsert_and_delete_opportunity_with_audit(): void
    {
        $this->actingAsRole('careerStaff');

        $created = $this->postJson('/api/v1/admin/career/opportunities', [
            'title' => 'Yaz Stajı',
            'kind' => 'internship',
            'organization' => 'Studio',
            'published' => true,
        ])->assertCreated()->json('data');

        $this->assertDatabaseHas('admin_audit_log', [
            'action' => 'create', 'target_type' => 'career_opportunity', 'target_label' => 'Yaz Stajı',
        ]);

        $this->postJson('/api/v1/admin/career/opportunities/'.$created['id'].'/delete')->assertOk();
        $this->assertDatabaseMissing('career_opportunities', ['id' => $created['id']]);
    }

    public function test_student_cannot_write_career_opportunities(): void
    {
        $this->actingAsUser();
        $this->postJson('/api/v1/admin/career/opportunities', [
            'title' => 'Hack', 'kind' => 'job',
        ])->assertStatus(403)->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_events_can_filter_by_bandabuliya_category_and_order_by_date(): void
    {
        Place::create([
            'id' => 'place-bandabuliya', 'name' => 'Bandabuliya', 'category' => 'Culture',
            'lat' => 35.3, 'lng' => 33.3, 'description' => 'x',
            'distance' => '10m', 'density' => 'quiet', 'street' => 'x',
            'accessible' => true, 'photos' => 0, 'rating' => 4.0,
        ]);
        Event::create([
            'id' => 'e-later', 'title' => 'Later', 'time' => '16:00',
            'event_date' => now()->addDays(3)->toDateString(),
            'place_name' => 'Bandabuliya', 'place_id' => 'place-bandabuliya',
            'category' => 'Bandabuliya', 'attendees' => 0, 'xp' => 10,
            'draft' => false, 'workflow_status' => 'published',
        ]);
        Event::create([
            'id' => 'e-soon', 'title' => 'Soon', 'time' => '18:00',
            'event_date' => now()->toDateString(),
            'place_name' => 'Bandabuliya', 'place_id' => 'place-bandabuliya',
            'category' => 'Bandabuliya', 'attendees' => 0, 'xp' => 10,
            'draft' => false, 'workflow_status' => 'published',
        ]);
        Event::create([
            'id' => 'e-other', 'title' => 'Other', 'time' => '12:00',
            'event_date' => now()->toDateString(),
            'place_name' => 'Garden', 'category' => 'Etkinlik',
            'attendees' => 0, 'xp' => 10, 'draft' => false, 'workflow_status' => 'published',
        ]);
        $this->actingAsUser();

        $data = $this->getJson('/api/v1/events?category=Bandabuliya')->assertOk()->json('data');
        $this->assertCount(2, $data);
        $this->assertSame(['e-soon', 'e-later'], array_column($data, 'id'));
    }

    public function test_clubs_can_filter_by_community_category(): void
    {
        \App\Models\Club::create(['id' => 'c-art', 'name' => 'Art', 'category' => 'Art']);
        \App\Models\Club::create(['id' => 'c-com', 'name' => 'Charity', 'category' => 'Community']);
        $this->actingAsUser();

        $data = $this->getJson('/api/v1/clubs?category=Community')->assertOk()->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('c-com', $data[0]['id']);
    }
}
