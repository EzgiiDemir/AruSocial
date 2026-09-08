<?php

namespace Database\Seeders;

use App\Models\Checkin;
use App\Models\Event;
use App\Models\FeedPost;
use App\Models\Place;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo campus life: feed posts, comments, likes, check-ins and a few dated
 * events.
 *
 * Several Home and Explore sections are deliberately hidden when they have
 * nothing to show ("Kampüste Şimdi" only renders `if (_feed.isNotEmpty)`),
 * so on a freshly migrated database the app looks half-built even though
 * every feature works. This gives those sections something real to render
 * and makes the check-in-driven "En Popüler Yerler" ranking meaningful.
 *
 * Everything is written under stable `demo-` ids so a re-run updates in
 * place, and so it can be identified and removed before real launch.
 */
class DemoCampusLifeSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::orderBy('id')->limit(5)->get();
        if ($users->isEmpty()) {
            $this->command?->warn('No users yet — run TestAccountsSeeder first.');

            return;
        }

        $places = Place::whereIn('id', [
            'meditation', 'the-garden', 'the-kiss', 'eternal-spring', 'titan', 'daniele',
        ])->get()->keyBy('id');
        if ($places->isEmpty()) {
            $places = Place::limit(6)->get()->keyBy('id');
        }

        $this->seedFeed($users, $places);
        $this->seedCheckins($users, $places);
        $this->seedEvents($places);
    }

    private function seedFeed($users, $places): void
    {
        $posts = [
            ['demo-post-1', 'Meditation kütüphanesinde ikinci kat bugün çok sakin, ders çalışmak için ideal.', 'meditation', 3],
            ['demo-post-2', 'Eternal Spring dijital baskı atölyesinde poster serimi bitirdim, çıktı kalitesi harika.', 'eternal-spring', 5],
            ['demo-post-3', 'The Garden’da öğle molası. Hava güzel, dışarıda oturan çok kişi var.', 'the-garden', 8],
            ['demo-post-4', 'Fotoğraf Kulübü çekimi için Girne limanına gidiyoruz, katılmak isteyen yazsın.', 'the-kiss', 6],
            ['demo-post-5', 'Titan’da öğrenci işleri kuyruğu şu an kısa, işi olan şimdi gitsin.', 'titan', 2],
            ['demo-post-6', 'Daniele stüdyosunda kısa film çekimi var, sessiz olalım lütfen.', 'daniele', 4],
        ];

        $placeIds = $places->keys()->all();

        foreach ($posts as $index => [$id, $text, $placeId, $likeCount]) {
            $author = $users[$index % $users->count()];
            $place = $places[$placeId] ?? ($placeIds ? $places[$placeIds[$index % count($placeIds)]] : null);

            $post = FeedPost::updateOrCreate(['id' => $id], [
                'author_id' => $author->id,
                'name' => $author->name,
                'text' => $text,
                'meta' => 'az önce',
                'visibility' => 'public',
                'post_type' => 'normal',
                'location_tag' => $place?->name,
                'official' => false,
                'workflow_status' => 'published',
                // Spread over the last two days so the timeline has shape.
                'created_at' => now()->subHours(($index + 1) * 5),
            ]);

            // Likes from distinct users, so counts are real rows.
            for ($i = 0; $i < min($likeCount, $users->count()); $i++) {
                PostLike::updateOrCreate(
                    ['post_id' => $post->id, 'user_id' => $users[$i]->id],
                    ['id' => 'demo-like-'.$post->id.'-'.$users[$i]->id, 'created_at' => now()],
                );
            }
        }

        $comments = [
            ['demo-comment-1', 'demo-post-1', 'Haber verdiğin için sağ ol, geliyorum.'],
            ['demo-comment-2', 'demo-post-3', 'Ben de oradayım, arka taraftaki masalardayız.'],
            ['demo-comment-3', 'demo-post-4', 'Saat kaçta buluşuyoruz?'],
            ['demo-comment-4', 'demo-post-2', 'Baskı ayarlarını paylaşabilir misin?'],
        ];

        foreach ($comments as $index => [$id, $postId, $text]) {
            if (! FeedPost::where('id', $postId)->exists()) {
                continue;
            }
            $author = $users[($index + 1) % $users->count()];
            PostComment::updateOrCreate(['id' => $id], [
                'post_id' => $postId,
                'user_id' => $author->id,
                'text' => $text,
                'meta' => 'az önce',
                'created_at' => now()->subHours($index + 1),
            ]);
        }
    }

    /**
     * Check-ins are what "En Popüler Yerler" ranks on, so the demo spread is
     * uneven on purpose — a flat distribution would make the ranking look
     * broken rather than realistic.
     */
    private function seedCheckins($users, $places): void
    {
        $weights = [
            'the-garden' => 9,
            'meditation' => 7,
            'eternal-spring' => 5,
            'titan' => 4,
            'the-kiss' => 3,
            'daniele' => 2,
        ];

        foreach ($places as $placeId => $place) {
            $count = $weights[$placeId] ?? 2;
            for ($i = 0; $i < $count; $i++) {
                $user = $users[$i % $users->count()];
                Checkin::updateOrCreate(
                    ['id' => 'demo-checkin-'.$placeId.'-'.$i],
                    [
                        'user_id' => $user->id,
                        'place_id' => $place->id,
                        'created_at' => now()->subMinutes(($i + 1) * 17),
                    ],
                );
            }
        }
    }

    private function seedEvents($places): void
    {
        $first = $places->first();
        $events = [
            ['demo-event-1', 'Fotoğraf Kulübü: Girne Limanı Çekimi', '15:00', now()->addDay(), 'Kulüp'],
            ['demo-event-2', 'Müzik Kulübü Jam Session', '18:30', now()->addDays(2), 'Kulüp'],
            ['demo-event-3', 'Portfolyo Sunum Günü', '11:00', now(), 'Akademik'],
        ];

        foreach ($events as [$id, $title, $time, $date, $category]) {
            Event::updateOrCreate(['id' => $id], [
                'title' => $title,
                'time' => $time,
                'event_date' => $date,
                'place_name' => $first?->name ?? 'ARUCAD',
                'place_id' => $first?->id,
                'category' => $category,
                'attendees' => 0,
                'xp' => 20,
                'draft' => false,
                'audience' => 'Tümü',
                'organizer' => 'ARUCAD',
                'workflow_status' => 'approved',
            ]);
        }
    }
}
