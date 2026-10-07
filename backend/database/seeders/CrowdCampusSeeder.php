<?php

namespace Database\Seeders;

use App\Models\CampusPresence;
use App\Models\Checkin;
use App\Models\FeedPost;
use App\Models\Place;
use App\Models\PostLike;
use App\Models\Story;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Makes a freshly seeded campus look populated: many student accounts,
 * recent visible check-ins (so PlacePresence yields busy / moderate / quiet),
 * and a longer social feed. Safe to re-run — emails are updateOrCreate.
 */
class CrowdCampusSeeder extends Seeder
{
    public function run(): void
    {
        $names = [
            'Elif Kaya', 'Mert Arslan', 'Deniz Yılmaz', 'Sude Tekin', 'Kaan Şahin',
            'Zeynep Aydın', 'Emre Doğan', 'Ceren Polat', 'Ali Rüzgar', 'Ece Demir',
            'Berk Özkan', 'İrem Çelik', 'Can Yıldız', 'Duru Aksoy', 'Onur Koç',
            'Melis Erdem', 'Yusuf Arı', 'Selin Uçar', 'Baran Güneş', 'Ada Kurt',
            'Efe Avcı', 'Lara Şimşek', 'Kerem Acar', 'Nazlı Bozkurt', 'Poyraz Eren',
            'Defne Karaca', 'Arda Mutlu', 'İlayda Sönmez', 'Tuna Ersoy', 'Asya Dil',
            'Bora Akın', 'Nehir Taş', 'Kuzey Yalçın', 'Mina Öztürk', 'Rüzgar Işık',
            'Lara Demirtaş', 'Ege Koçak', 'Su Yücel', 'Atlas Kılıç', 'Pelin Arıkan',
        ];

        $users = [];
        foreach ($names as $i => $name) {
            $slug = Str::slug(Str::before($name, ' ')).($i + 1);
            $users[] = User::updateOrCreate(
                ['email' => $slug.'@arucad.edu.tr'],
                [
                    'name' => $name,
                    'password' => 'campus-demo',
                    'xp' => 400 + ($i * 73),
                    'level' => 2 + ($i % 8),
                    'places' => 3 + ($i % 12),
                    'events' => 1 + ($i % 6),
                    'department' => ['Grafik Tasarım', 'Mimarlık', 'Moda Tasarımı', 'Sinema'][$i % 4],
                    'year' => ((string) (1 + ($i % 4))).'. Sınıf',
                    'university' => 'ARUCAD',
                ],
            );
        }

        $busyId = Place::where('id', 'the-garden')->exists() ? 'the-garden' : 'place-garden';
        $midId = Place::where('id', 'the-kiss')->exists() ? 'the-kiss' : 'titan';
        // art-rooms intentionally gets no crowd check-ins → quiet / Sakin.

        Checkin::where('id', 'like', 'crowd-%')->delete();

        $now = now();
        foreach (array_slice($users, 0, 8) as $i => $user) {
            Checkin::create([
                'id' => 'crowd-busy-'.$i,
                'place_id' => $busyId,
                'user_id' => $user->id,
                'visible_to_others' => true,
                'created_at' => $now->copy()->subMinutes($i * 4),
            ]);
        }
        foreach (array_slice($users, 8, 3) as $i => $user) {
            Checkin::create([
                'id' => 'crowd-mid-'.$i,
                'place_id' => $midId,
                'user_id' => $user->id,
                'visible_to_others' => true,
                'created_at' => $now->copy()->subMinutes(8 + $i * 5),
            ]);
        }

        // Feed the same short-lived aggregate that real GPS pings use.
        // Uneven counts produce quiet, warm and hot areas on the real
        // MapLibre heat layer; Flutter contains no hardcoded demo dots and
        // the public response still exposes totals only, never identities.
        $presencePlan = [
            $busyId => 12,
            $midId => 8,
            'titan' => 6,
            'meditation' => 5,
            'daniele' => 4,
            'eternal-spring' => 3,
            'art-rooms' => 2,
        ];
        $presencePlan = array_filter(
            $presencePlan,
            fn (int $count, string $placeId) => Place::whereKey($placeId)->exists(),
            ARRAY_FILTER_USE_BOTH,
        );
        CampusPresence::whereIn('user_id', collect($users)->pluck('id'))->delete();
        $cursor = 0;
        foreach ($presencePlan as $placeId => $count) {
            foreach (array_slice($users, $cursor, $count) as $offset => $user) {
                $user->forceFill([
                    'location_visibility' => 'public',
                    'nearby_discoverable' => true,
                    'check_in_visible' => true,
                ])->save();
                CampusPresence::updateOrCreate(
                    ['user_id' => $user->id],
                    [
                        'place_id' => $placeId,
                        'updated_at' => $now->copy()->subSeconds(($cursor + $offset) * 7),
                    ],
                );
            }
            $cursor += $count;
        }

        $snippets = [
            'Titan’da öğrenci işleri kuyruğu uzadı 😅',
            'Garden’da kahve molası.',
            'Atelier’de maket teslimi gecesi.',
            'Meditation sessiz, çalışmaya uygun.',
            'The Kiss galeride yeni işler var.',
            'Basketbol antrenmanı iptal olmadı, salondayız.',
            'Yemekhanede mercimek bitmiş — ikinci tura geçtik.',
            'Bahar Şenliği stant kurulumu başladı!',
            'MAC Lab dolu, 20 dk bekledim.',
            'Kampüs girişinde güneş muhteşem.',
            'Daniele stüdyoda çekim var, sessiz olun.',
            'Kütüphanede priz savaşı.',
        ];
        PostLike::where('id', 'like', 'crowd-like-%')->delete();
        FeedPost::where('id', 'like', 'crowd-post-%')->delete();
        foreach ($snippets as $i => $text) {
            $author = $users[$i % count($users)];
            $row = [
                'id' => 'crowd-post-'.$i,
                'author_id' => $author->id,
                'name' => $author->name,
                'text' => $text,
                'meta' => ($i + 1).' dk önce',
                'official' => false,
                'created_at' => $now->copy()->subMinutes($i * 7),
            ];
            if (Schema::hasColumn('feed_posts', 'visibility')) {
                $row['visibility'] = 'everyone';
            }
            if (Schema::hasColumn('feed_posts', 'workflow_status')) {
                $row['workflow_status'] = 'published';
            }
            $post = FeedPost::create($row);
            $likeCount = 6 + ($i % 9);
            for ($n = 0; $n < $likeCount; $n++) {
                $liker = $users[($i + $n + 1) % count($users)];
                PostLike::create([
                    'id' => 'crowd-like-'.$i.'-'.$n,
                    'post_id' => $post->id,
                    'user_id' => $liker->id,
                    'created_at' => $now->copy()->subMinutes($i * 7 + $n),
                ]);
            }
        }

        Story::where('id', 'like', 'crowd-story-%')->delete();
        $storyTexts = [
            'Titan kuyruğu',
            'Garden kahve',
            'Atelier teslim',
            'Meditation sessiz',
            'The Kiss sergi',
            'Bahar Şenliği',
        ];
        $storyColors = [0xFFFDE021, 0xFF000F9F, 0xFF2A7C13, 0xFF1C1E22, 0xFFFDE021, 0xFF000F9F];
        foreach ($storyTexts as $i => $text) {
            $author = $users[$i % count($users)];
            $story = [
                'id' => 'crowd-story-'.$i,
                'author_id' => $author->id,
                'author_name' => $author->name,
                'text' => $text,
                'background_color_value' => $storyColors[$i],
                'created_at' => $now->copy()->subMinutes($i * 11),
            ];
            if (Schema::hasColumn('stories', 'visibility')) {
                $story['visibility'] = 'everyone';
            }
            Story::create($story);
        }
    }
}
