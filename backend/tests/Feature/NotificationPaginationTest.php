<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_pages_are_scoped_to_the_signed_in_account(): void
    {
        $alice = User::create(['name' => 'Alice', 'email' => 'alice@arucad.edu.tr', 'password' => bcrypt('x')]);
        $bob = User::create(['name' => 'Bob', 'email' => 'bob@arucad.edu.tr', 'password' => bcrypt('x')]);

        for ($i = 1; $i <= 12; $i++) {
            Notification::create([
                'id' => 'na-'.$i,
                'user_id' => $alice->id,
                'kind' => 'like',
                'title' => 'Alice '.$i,
                'body' => 'body',
                'created_at' => now()->subMinutes(12 - $i),
            ]);
            Notification::create([
                'id' => 'nb-'.$i,
                'user_id' => $bob->id,
                'kind' => 'like',
                'title' => 'Bob '.$i,
                'body' => 'body',
                'created_at' => now()->subMinutes(12 - $i),
            ]);
        }

        $this->actingAsUser($alice);
        $page1 = $this->getJson('/api/v1/notifications?page=1&perPage=5')->assertOk();
        $page2 = $this->getJson('/api/v1/notifications?page=2&perPage=5')->assertOk();

        $titles = collect($page1->json('data'))->pluck('title')
            ->concat(collect($page2->json('data'))->pluck('title'));
        $this->assertTrue($titles->every(fn ($t) => str_starts_with($t, 'Alice ')));
        $this->assertFalse($titles->contains(fn ($t) => str_starts_with($t, 'Bob ')));
        $this->assertSame(12, $page1->json('meta.pagination.total'));
        $this->assertCount(5, $page1->json('data'));
        $this->assertEmpty(collect($page1->json('data'))->pluck('id')->intersect(collect($page2->json('data'))->pluck('id')));
    }

    public function test_older_notifications_are_reachable_past_the_old_100_cap(): void
    {
        $me = $this->actingAsUser();
        for ($i = 1; $i <= 110; $i++) {
            Notification::create([
                'id' => 'hist-'.$i,
                'user_id' => $me->id,
                'kind' => 'comment',
                'title' => 'hist '.$i,
                'body' => 'body',
                'created_at' => now()->subMinutes(110 - $i),
            ]);
        }

        $page1 = $this->getJson('/api/v1/notifications?page=1&perPage=50')->assertOk();
        $page3 = $this->getJson('/api/v1/notifications?page=3&perPage=50')->assertOk();

        $this->assertSame(110, $page1->json('meta.pagination.total'));
        $this->assertSame(3, $page1->json('meta.pagination.lastPage'));
        $this->assertCount(10, $page3->json('data'));
        $this->assertSame('hist-1', $page3->json('data.9.id'));
        $this->assertSame('hist-110', $page1->json('data.0.id'));
    }

    public function test_marking_read_does_not_drop_the_row_from_later_pages(): void
    {
        $me = $this->actingAsUser();
        for ($i = 1; $i <= 6; $i++) {
            Notification::create([
                'id' => 'n-'.$i,
                'user_id' => $me->id,
                'kind' => 'comment',
                'title' => 'n '.$i,
                'body' => 'body',
                'created_at' => now()->subMinutes(6 - $i),
            ]);
        }

        $this->postJson('/api/v1/notifications/n-6/read')->assertOk();
        $page1 = $this->getJson('/api/v1/notifications?page=1&perPage=5')->assertOk();
        $row = collect($page1->json('data'))->firstWhere('id', 'n-6');
        $this->assertNotNull($row);
        $this->assertTrue($row['read']);
        $this->assertSame(6, $page1->json('meta.pagination.total'));
    }
}
