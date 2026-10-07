<?php

namespace App\Http\Controllers\Api;

use App\Events\CampusDataChanged;
use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Api\Concerns\ModeratesContent;
use App\Http\Controllers\Api\Concerns\SubmitsReports;
use App\Http\Controllers\Controller;
use App\Http\Requests\SetPlaceCoverRequest;
use App\Http\Requests\UpsertPlaceRequest;
use App\Models\CollaborationPost;
use App\Models\Event;
use App\Models\Place;
use App\Models\Review;
use App\Models\WorkshopEquipmentItem;
use App\Services\AchievementEvaluator;
use App\Services\ActivityLogger;
use App\Services\AuditLogger;
use App\Services\PlacePresence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaceController extends Controller
{
    use ApiResponds, ModeratesContent, SubmitsReports;

    private function placeToJson(Place $p, PlacePresence $presence): array
    {
        $recent = $presence->recentCheckinsFor($p->id);

        return [
            'id' => $p->id,
            'name' => $p->name,
            'category' => $p->category,
            'lat' => $p->lat,
            'lng' => $p->lng,
            'description' => $p->description,
            'distance' => $p->distance,
            // Derived from check-ins in the last PlacePresence::WINDOW_HOURS
            // — the static `places.density` column is not what the client
            // sees. Values stay the quiet/moderate/busy strings
            // `campusDensityInfo` already understands.
            'density' => $presence->densityFor($p->id),
            'street' => $p->street,
            'tourUrl' => $p->tour_url,
            'tourTarget' => $p->tour_target,
            'accessible' => $p->accessible,
            'photos' => $p->photos,
            // Review average, or 0 when nobody has rated yet. The static
            // `places.rating` seed column is not returned.
            'rating' => $presence->ratingFor($p->id),
            'coverUrl' => $p->cover_url,
            'recentCheckins' => $recent,
            'totalCheckins' => $presence->totalCheckinsFor($p->id),
            'recentCheckinEntries' => $presence->recentCheckinEntriesFor($p->id),
        ];
    }

    private function reviewToJson(Review $r): array
    {
        return $r->toApiArray();
    }

    public function index(): JsonResponse
    {
        // Hide legacy `place-*` aliases when a canonical row shares the name
        // (avoids "The Garden" twice in Keşfet → Yerler).
        $all = Place::query()->orderBy('name')->get();
        $canonicalNames = $all
            ->reject(fn (Place $p) => str_starts_with($p->id, 'place-'))
            ->map(fn (Place $p) => mb_strtolower(trim($p->name)))
            ->unique()
            ->all();
        $places = $all->filter(function (Place $p) use ($canonicalNames) {
            if (! str_starts_with($p->id, 'place-')) {
                return true;
            }

            return ! in_array(mb_strtolower(trim($p->name)), $canonicalNames, true);
        })->values();

        // Final name dedupe — keep first (canonical preferred by order above).
        $seen = [];
        $places = $places->filter(function (Place $p) use (&$seen) {
            $key = mb_strtolower(trim($p->name));
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;

            return true;
        })->values();

        $presence = PlacePresence::forIds($places->pluck('id')->all());

        return $this->ok($places->map(fn ($p) => $this->placeToJson($p, $presence)));
    }

    public function show(string $id): JsonResponse
    {
        $place = Place::find($id);
        if (! $place) {
            return $this->fail(404, 'PLACE_NOT_FOUND', 'Place not found.');
        }

        return $this->ok($this->placeToJson($place, PlacePresence::forIds([$place->id])));
    }

    public function setCover(SetPlaceCoverRequest $request, string $id): JsonResponse
    {
        $place = Place::find($id);
        if (! $place) {
            return $this->fail(404, 'PLACE_NOT_FOUND', 'Place not found.');
        }

        $place->cover_url = $request->input('url');
        $place->save();
        $this->announcePlaceChange('cover_updated', $place->id);

        return $this->ok($this->placeToJson($place, PlacePresence::forIds([$place->id])));
    }

    public function reviews(string $id): JsonResponse
    {
        $reviews = Review::with('user')->where('place_id', $id)->orderByDesc('created_at')->get();

        return $this->ok($reviews->map(fn ($r) => $this->reviewToJson($r)));
    }

    public function addReview(Request $request, string $id): JsonResponse
    {
        $place = Place::find($id);
        if (! $place) {
            return $this->fail(404, 'PLACE_NOT_FOUND', 'Place not found.');
        }
        $rating = max(1, min(5, (int) $request->input('rating', 1)));
        $comment = (string) $request->input('comment', '');
        $me = $this->currentUser();
        if ($comment !== '' && ($blocked = $this->moderationBlock($me, $comment, 'review', 'place.review'))) {
            return $blocked;
        }

        $review = Review::create([
            'id' => $this->newId('review'),
            'place_id' => $place->id,
            'user_id' => $me->id,
            'rating' => $rating,
            'comment' => $comment,
            'meta' => 'az önce',
            'created_at' => now(),
            'moderation_status' => 'approved',
        ]);
        $review->setRelation('user', $me);

        ActivityLogger::log(
            $me->id,
            'review',
            "Puanladın: {$place->name}",
            str_repeat('⭐', $rating).' · '.($comment ?: 'yorum yok'),
        );

        AchievementEvaluator::evaluate($me);
        $this->announcePlaceChange('reviewed', $place->id);

        return $this->ok($this->reviewToJson($review));
    }

    // Real "boş/dolu" mekân müsaitliği (docs/EKSIKLER.md §4): what's
    // already booked at this place on this date, so both the admin event
    // form and the student "kendi aktivite" form can show real conflicts
    // before submitting — same data the server-side conflict check in
    // EventController/Admin\EventController::upsert() actually enforces.
    public function availability(Request $request, string $id): JsonResponse
    {
        $date = $request->query('date');
        if (! $date) {
            return $this->fail(400, 'VALIDATION', 'date query param is required (YYYY-MM-DD).');
        }

        $booked = Event::where('place_id', $id)
            ->whereDate('event_date', $date)
            ->whereNotIn('workflow_status', ['rejected'])
            ->get(['id', 'title', 'time', 'workflow_status']);

        return $this->ok($booked->map(fn (Event $e) => [
            'eventId' => $e->id,
            'title' => $e->title,
            'time' => $e->time,
            'workflowStatus' => $e->workflow_status,
        ]));
    }

    public function upsert(UpsertPlaceRequest $request): JsonResponse
    {
        if ($blocked = $this->moderationBlock(
            $this->currentUser(),
            $this->moderationText($request->validated()),
            'catalog',
            'admin.place.upsert',
        )) {
            return $blocked;
        }
        $id = $request->input('id');
        $isNew = ! Place::where('id', $id)->exists();

        $place = Place::updateOrCreate(['id' => $id], [
            'name' => $request->input('name'),
            'category' => $request->input('category'),
            'lat' => $request->input('lat'),
            'lng' => $request->input('lng'),
            'description' => $request->input('description', ''),
            'distance' => $request->input('distance', ''),
            'street' => $request->input('street', ''),
            'tour_url' => $request->input('tourUrl'),
            'tour_target' => $request->input('tourTarget'),
            'accessible' => $request->boolean('accessible', true),
        ]);
        AuditLogger::logAsCurrentUser($isNew ? 'create' : 'update', 'place', $place->name);
        $this->announcePlaceChange($isNew ? 'created' : 'updated', $place->id);

        return $this->ok(
            $this->placeToJson($place, PlacePresence::forIds([$place->id])),
            $isNew ? 201 : 200,
        );
    }

    public function destroy(string $id): JsonResponse
    {
        $place = Place::find($id);
        if ($place) {
            AuditLogger::logAsCurrentUser('delete', 'place', $place->name);
            $place->delete();
            $this->announcePlaceChange('deleted', $id);
        }

        return $this->ok(['deleted' => true]);
    }

    private function announcePlaceChange(string $action, string $id): void
    {
        try {
            broadcast(new CampusDataChanged(['places'], $action, $id));
        } catch (\Throwable) {
            // REST remains the source of truth when broadcasting is offline.
        }
    }

    public function report(Request $request, string $id): JsonResponse
    {
        $place = Place::find($id);
        if (! $place) {
            return $this->fail(404, 'PLACE_NOT_FOUND', 'Place not found.');
        }
        $me = $this->currentUser();
        ActivityLogger::log($me->id, 'report', "Şikayet ettin: {$place->name}",
            (string) $request->input('reason', ''));

        return $this->submitReport(
            request: $request,
            reporter: $me,
            targetType: 'place',
            targetId: (string) $place->id,
            targetLabel: (string) $place->name,
            sourceFeature: 'place.report',
        );
    }

    private function equipmentToJson(WorkshopEquipmentItem $e): array
    {
        return [
            'id' => $e->id,
            'name' => $e->name,
            'available' => $e->available,
        ];
    }

    private function collaborationPostToJson(CollaborationPost $p): array
    {
        return [
            'id' => $p->id,
            'authorId' => (string) $p->author_id,
            'authorName' => $p->author?->name,
            'text' => $p->text,
            'createdAt' => $p->created_at?->toIso8601String(),
        ];
    }

    // Real replacement for the Flutter map sheet's previously hardcoded,
    // identical-on-every-place `_workshopEquipment` / `_collaborationBoard`
    // consts. Equipment is admin/trainer-managed; posts are student-created
    // and auto-expire so the board never goes stale.
    public function workshop(string $id): JsonResponse
    {
        $place = Place::find($id);
        if (! $place) {
            return $this->fail(404, 'PLACE_NOT_FOUND', 'Place not found.');
        }

        $equipment = WorkshopEquipmentItem::where('place_id', $id)
            ->orderBy('sort_order')
            ->get();
        $posts = CollaborationPost::where('place_id', $id)
            ->where('expires_at', '>=', now())
            ->orderByDesc('created_at')
            ->with('author:id,name')
            ->get();

        return $this->ok([
            'equipment' => $equipment->map(fn ($e) => $this->equipmentToJson($e))->values(),
            'posts' => $posts->map(fn ($p) => $this->collaborationPostToJson($p))->values(),
        ]);
    }

    public function addWorkshopPost(Request $request, string $id): JsonResponse
    {
        $place = Place::find($id);
        if (! $place) {
            return $this->fail(404, 'PLACE_NOT_FOUND', 'Place not found.');
        }
        $text = trim((string) $request->input('text', ''));
        if ($text === '') {
            return $this->fail(400, 'VALIDATION', 'text is required.');
        }
        $me = $this->currentUser();
        if ($blocked = $this->moderationBlock($me, $text, 'workshop_post', 'place.addWorkshopPost')) {
            return $blocked;
        }

        $post = CollaborationPost::create([
            'id' => $this->newId('collab'),
            'place_id' => $id,
            'author_id' => $me->id,
            'text' => $text,
            'created_at' => now(),
            'expires_at' => now()->addDays(14),
            'moderation_status' => 'approved',
        ]);
        $post->setRelation('author', $me);

        return $this->ok($this->collaborationPostToJson($post));
    }

    public function upsertWorkshopEquipment(Request $request, string $id): JsonResponse
    {
        $place = Place::find($id);
        if (! $place) {
            return $this->fail(404, 'PLACE_NOT_FOUND', 'Place not found.');
        }
        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return $this->fail(400, 'VALIDATION', 'name is required.');
        }
        if ($blocked = $this->moderationBlock(
            $this->currentUser(),
            $name,
            'workshop_equipment',
            'place.upsertWorkshopEquipment',
        )) {
            return $blocked;
        }
        $itemId = $request->input('id');
        $isNew = ! $itemId || ! WorkshopEquipmentItem::where('id', $itemId)->exists();
        $item = WorkshopEquipmentItem::updateOrCreate(
            ['id' => $itemId ?: $this->newId('equip')],
            [
                'place_id' => $id,
                'name' => $name,
                'available' => (bool) $request->input('available', true),
                'sort_order' => (int) $request->input('sortOrder', 0),
                'updated_by' => $this->currentUser()->name,
            ],
        );
        AuditLogger::logAsCurrentUser($isNew ? 'create' : 'update', 'workshop_equipment', "{$place->name}: {$name}");

        return $this->ok($this->equipmentToJson($item), $isNew ? 201 : 200);
    }

    public function destroyWorkshopEquipment(string $id, string $itemId): JsonResponse
    {
        $item = WorkshopEquipmentItem::where('id', $itemId)->where('place_id', $id)->first();
        if ($item) {
            AuditLogger::logAsCurrentUser('delete', 'workshop_equipment', $item->name);
            $item->delete();
        }

        return $this->ok(['deleted' => true]);
    }

    public function destroyWorkshopPost(string $id, string $postId): JsonResponse
    {
        $post = CollaborationPost::where('id', $postId)->where('place_id', $id)->first();
        if ($post) {
            AuditLogger::logAsCurrentUser('delete', 'collaboration_post', $post->text);
            $post->delete();
        }

        return $this->ok(['deleted' => true]);
    }
}
