<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\ModerationReport;
use App\Models\Place;
use App\Models\Review;
use App\Services\ActivityLogger;
use App\Services\ModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlaceController extends Controller
{
    use ApiResponds;

    private function placeToJson(Place $p): array
    {
        return [
            'id' => $p->id,
            'name' => $p->name,
            'category' => $p->category,
            'lat' => $p->lat,
            'lng' => $p->lng,
            'description' => $p->description,
            'distance' => $p->distance,
            'density' => $p->density,
            'street' => $p->street,
            'tourUrl' => $p->tour_url,
            'accessible' => $p->accessible,
            'photos' => $p->photos,
            'rating' => $p->rating,
        ];
    }

    private function reviewToJson(Review $r): array
    {
        return [
            'id' => $r->id,
            'placeId' => $r->place_id,
            'author' => $r->author,
            'rating' => $r->rating,
            'comment' => $r->comment,
            'meta' => $r->meta,
        ];
    }

    public function index(): JsonResponse
    {
        return $this->ok(Place::all()->map(fn ($p) => $this->placeToJson($p)));
    }

    public function show(string $id): JsonResponse
    {
        $place = Place::find($id);
        if (! $place) {
            return $this->fail(404, 'PLACE_NOT_FOUND', 'Place not found.');
        }

        return $this->ok($this->placeToJson($place));
    }

    public function reviews(string $id): JsonResponse
    {
        $reviews = Review::where('place_id', $id)->orderByDesc('created_at')->get();

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
        if ($comment !== '' && ($blocked = ModerationService::checkText($me, $comment))) {
            return $this->fail(400, 'CONTENT_BLOCKED', $blocked);
        }

        $review = Review::create([
            'id' => $this->newId('review'),
            'place_id' => $place->id,
            'author' => $me->name,
            'rating' => $rating,
            'comment' => $comment,
            'meta' => 'az önce',
            'created_at' => now(),
        ]);

        ActivityLogger::log(
            $me->id,
            'review',
            "Puanladın: {$place->name}",
            str_repeat('⭐', $rating).' · '.($comment ?: 'yorum yok'),
        );

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

    public function report(Request $request, string $id): JsonResponse
    {
        $place = Place::find($id);
        if (! $place) {
            return $this->fail(404, 'PLACE_NOT_FOUND', 'Place not found.');
        }
        $reason = (string) $request->input('reason', '');
        $me = $this->currentUser();

        ActivityLogger::log($me->id, 'report', "Şikayet ettin: {$place->name}", $reason);

        ModerationReport::create([
            'id' => $this->newId('report'),
            'kind' => 'place',
            'target_id' => $place->id,
            'target_label' => $place->name,
            'reason' => $reason,
            'reported_at' => now(),
        ]);

        return $this->ok(['reported' => true]);
    }
}
