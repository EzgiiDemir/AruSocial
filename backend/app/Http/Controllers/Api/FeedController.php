<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginatedListRequest;
use App\Models\FeedPost;
use App\Models\ModerationReport;
use App\Models\Notification as InboxNotification;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\ModerationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedController extends Controller
{
    use ApiResponds;

    // `likes` and `likedByMe` are the same two field names the Flutter DTO
    // has always parsed, but they are no longer columns: both are derived
    // from post_likes, and `likedByMe` is answered for whoever is asking.
    // Two accounts reading the same post get the same `likes` and
    // legitimately different `likedByMe`.
    private function postToJson(FeedPost $p): array
    {
        return [
            'id' => $p->id,
            'authorId' => (string) $p->author_id,
            'name' => $p->name,
            'text' => $p->text,
            'meta' => $p->meta,
            'likes' => (int) ($p->likes_count ?? $p->likes()->count()),
            'likedByMe' => (bool) $p->liked_by_current_user,
            'imageUrl' => $p->image_url,
            'comments' => $p->comments->map(fn ($c) => $c->toApiArray()),
            'visibility' => $p->visibility,
            'postType' => $p->post_type,
            'courseTag' => $p->course_tag,
            'locationTag' => $p->location_tag,
            'official' => $p->official,
        ];
    }

    /**
     * Loads posts with their like count and whether *this* caller liked
     * each one, both as aggregates in the same query — the alternative is
     * two extra queries per post in the feed.
     */
    private function postsFor(User $me): Builder
    {
        return FeedPost::with(['comments.user'])
            ->withCount('likes')
            ->withExists(['likes as liked_by_current_user' => fn ($q) => $q->where('user_id', $me->id)]);
    }

    public function index(PaginatedListRequest $request): JsonResponse
    {
        $query = $this->postsFor($this->currentUser())
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return $this->okPage($query, $request, fn ($p) => $this->postToJson($p));
    }

    public function store(Request $request): JsonResponse
    {
        $me = $this->currentUser();
        $text = (string) $request->input('text', '');
        if ($blocked = ModerationService::checkText($me, $text)) {
            return $this->fail(400, 'CONTENT_BLOCKED', $blocked);
        }
        $visibility = $request->input('visibility') === 'onlyMe' ? 'onlyMe' : 'everyone';

        $post = FeedPost::create([
            'id' => $this->newId('post'),
            'author_id' => $me->id,
            'name' => $me->name,
            'text' => $text,
            'meta' => 'az önce',
            'image_url' => $request->input('imageUrl'),
            'visibility' => $visibility,
            'post_type' => $request->input('postType', 'normal'),
            'course_tag' => $request->input('courseTag'),
            'location_tag' => $request->input('locationTag'),
            'official' => false,
            'created_at' => now(),
        ]);
        // No ActivityKind value represents "created a post" — see
        // ActivityLogger's doc comment. Nothing to log here on purpose.

        return $this->ok($this->postToJson($this->reload($post->id, $me)));
    }

    public function like(string $id): JsonResponse
    {
        $me = $this->currentUser();
        $post = FeedPost::find($id);
        if (! $post) {
            return $this->fail(404, 'POST_NOT_FOUND', 'Post not found.');
        }

        // Toggle by row, for whoever is signed in. firstOrCreate rather
        // than "look, then insert" so two taps racing each other can't both
        // decide there's no like yet — the second one loses to the unique
        // index instead of creating a duplicate.
        $like = PostLike::firstOrCreate(
            ['post_id' => $post->id, 'user_id' => $me->id],
            ['id' => $this->newId('like'), 'created_at' => now()],
        );

        $nowLiked = $like->wasRecentlyCreated;
        if (! $nowLiked) {
            // It already existed, so this tap is an unlike — and it can
            // only ever remove this account's own row.
            $like->delete();
        } else {
            ActivityLogger::log($me->id, 'like', "Beğendin: {$post->name}", $post->text);
            $this->notifyPostOwner($post, $me, 'like', 'Yeni beğeni', "{$me->name} gönderini beğendi.");
        }

        return $this->ok($this->postToJson($this->reload($post->id, $me)));
    }

    private function reload(string $id, User $me): FeedPost
    {
        return $this->postsFor($me)->findOrFail($id);
    }

    public function comment(Request $request, string $id): JsonResponse
    {
        $post = FeedPost::find($id);
        if (! $post) {
            return $this->fail(404, 'POST_NOT_FOUND', 'Post not found.');
        }
        $me = $this->currentUser();
        $text = (string) $request->input('text', '');
        if ($blocked = ModerationService::checkText($me, $text)) {
            return $this->fail(400, 'CONTENT_BLOCKED', $blocked);
        }

        $createdAt = now();
        $latest = PostComment::where('post_id', $post->id)->max('created_at');
        if ($latest && $createdAt->lte($latest)) {
            $createdAt = \Illuminate\Support\Carbon::parse($latest)->addSecond();
        }

        PostComment::create([
            'id' => $this->newId('comment'),
            'post_id' => $post->id,
            'user_id' => $me->id,
            'text' => $text,
            'meta' => 'az önce',
            'created_at' => $createdAt,
        ]);
        ActivityLogger::log($me->id, 'comment', "Yorum yaptın: {$post->name}", $text);
        $this->notifyPostOwner($post, $me, 'comment', 'Yeni yorum', "{$me->name} gönderine yorum yaptı.");

        return $this->ok($this->postToJson($this->reload($post->id, $me)));
    }

    private function notifyPostOwner(FeedPost $post, User $actor, string $kind, string $title, string $body): void
    {
        $ownerId = (int) $post->author_id;
        if ($ownerId === 0 || $ownerId === (int) $actor->id) {
            return;
        }
        $owner = User::query()->find($ownerId);
        if ($owner === null) {
            return;
        }
        InboxNotification::notify($owner, $actor, $kind, $title, $body);
    }

    public function report(Request $request, string $id): JsonResponse
    {
        $post = FeedPost::find($id);
        if (! $post) {
            return $this->fail(404, 'POST_NOT_FOUND', 'Post not found.');
        }
        $reason = (string) $request->input('reason', '');
        $me = $this->currentUser();

        ActivityLogger::log($me->id, 'report', "Gönderiyi şikayet ettin: {$post->name}", $reason);

        ModerationReport::create([
            'id' => $this->newId('report'),
            'kind' => 'post',
            'target_id' => $post->id,
            'target_label' => mb_substr($post->text, 0, 60) ?: $post->name,
            'reason' => $reason,
            'reported_at' => now(),
        ]);

        return $this->ok(['reported' => true]);
    }
}
