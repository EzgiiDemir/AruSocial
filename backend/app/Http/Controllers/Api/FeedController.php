<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Events\CampusDataChanged;
use App\Http\Requests\PaginatedListRequest;
use App\Models\FeedPost;
use App\Models\ModerationReport;
use App\Models\Notification as InboxNotification;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\GranularPermissions;
use App\Services\ModerationService;
use App\Support\MediaPublicUrl;
use App\Support\SchemaColumnCache;
use App\Support\SocialAudience;
use App\Support\SocialMediaAttachment;
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
            'authorAvatarUrl' => MediaPublicUrl::rewrite($p->user?->avatar_url),
            'text' => $p->text,
            'meta' => $p->meta,
            'likes' => (int) ($p->likes_count ?? $p->likes()->count()),
            'likedByMe' => (bool) $p->liked_by_current_user,
            'imageUrl' => MediaPublicUrl::rewrite($p->image_url),
            'mediaMimeType' => $p->media_mime_type,
            'comments' => $p->comments->map(fn ($c) => $c->toApiArray()),
            'visibility' => $p->visibility,
            'postType' => $p->post_type,
            'courseTag' => $p->course_tag,
            'locationTag' => $p->location_tag,
            'official' => $p->official,
            'workflowStatus' => $p->workflow_status ?? 'published',
            'createdAt' => $p->created_at?->toIso8601String(),
            'reviewNote' => $p->review_note,
            'isPinned' => (bool) $p->is_pinned,
            'pinnedAt' => $p->pinned_at?->toIso8601String(),
        ];
    }

    /**
     * Loads posts with their like count and whether *this* caller liked
     * each one, both as aggregates in the same query — the alternative is
     * two extra queries per post in the feed.
     */
    private function postsFor(User $me): Builder
    {
        $query = FeedPost::with(['comments.user', 'user:id,avatar_url'])
            ->withCount('likes')
            ->withExists(['likes as liked_by_current_user' => fn ($q) => $q->where('user_id', $me->id)]);

        // Real fix for "the feed is slow": Schema::hasColumn() used to
        // re-query the database's own schema metadata on every single feed
        // load — the busiest endpoint in the app. SchemaColumnCache answers
        // it once per worker process instead, while still supporting a
        // database that hasn't run this migration yet.
        if (SchemaColumnCache::hasColumn('feed_posts', 'workflow_status')) {
            $query->where(function ($q) use ($me) {
                $q->where(function ($pub) {
                    $pub->where('workflow_status', 'published')
                        ->orWhereNull('workflow_status');
                })->orWhere(function ($own) use ($me) {
                    $own->where('author_id', $me->id)
                        ->whereIn('workflow_status', ['published', 'pending_review']);
                });
            });
        }

        return $query;
    }

    public function index(PaginatedListRequest $request): JsonResponse
    {
        $me = $this->currentUser();
        $query = $this->postsFor($me);
        SocialAudience::constrainFeed($query, $me);
        $this->constrainPrivateAuthors($query, $me);
        $query->orderByDesc('is_pinned')
            ->orderByDesc('pinned_at')
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
        if (mb_strlen($text) > 2000) {
            return $this->fail(400, 'VALIDATION', 'Text must be at most 2000 characters.');
        }
        $visibility = SocialAudience::visibility($request->input('visibility'));
        $needsReview = (bool) config('services.feed.require_approval', false);
        $attachment = SocialMediaAttachment::resolve($request->input('imageUrl'), $me);
        if ($attachment['code'] !== null) {
            return $this->fail(422, $attachment['code'], $attachment['message']);
        }

        $row = [
            'id' => $this->newId('post'),
            'author_id' => $me->id,
            'name' => $me->name,
            'text' => $text,
            'meta' => $needsReview ? 'incelemede' : 'az önce',
            'image_url' => $attachment['url'],
            'media_mime_type' => $attachment['item']?->mime_type,
            'visibility' => $visibility,
            'post_type' => $request->input('postType', 'normal'),
            'course_tag' => $request->input('courseTag'),
            'location_tag' => $request->input('locationTag'),
            'official' => false,
            'created_at' => now(),
        ];
        if (SchemaColumnCache::hasColumn('feed_posts', 'workflow_status')) {
            $row['workflow_status'] = $needsReview ? 'pending_review' : 'published';
        }
        $post = FeedPost::create($row);
        // No ActivityKind value represents "created a post" — see
        // ActivityLogger's doc comment. Nothing to log here on purpose.

        $this->announceFeedChange(
            $needsReview ? ['moderation'] : ['feed'],
            $needsReview ? 'submitted_for_review' : 'created',
            $post->id,
        );

        return $this->ok($this->postToJson($this->reload($post->id, $me)));
    }

    public function like(string $id): JsonResponse
    {
        $me = $this->currentUser();
        $post = $this->visiblePost($id, $me);
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

        $this->announceFeedChange(['feed'], $nowLiked ? 'liked' : 'unliked', $post->id);

        return $this->ok($this->postToJson($this->reload($post->id, $me)));
    }

    private function reload(string $id, User $me): FeedPost
    {
        return $this->postsFor($me)->findOrFail($id);
    }

    public function comment(Request $request, string $id): JsonResponse
    {
        $me = $this->currentUser();
        $post = $this->visiblePost($id, $me);
        if (! $post) {
            return $this->fail(404, 'POST_NOT_FOUND', 'Post not found.');
        }
        $text = (string) $request->input('text', '');
        if (mb_strlen($text) > 2000) {
            return $this->fail(400, 'VALIDATION', 'Text must be at most 2000 characters.');
        }
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
        $this->announceFeedChange(['feed'], 'commented', $post->id);

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
        InboxNotification::notify($owner, $actor, $kind, $title, $body, [
            'postId' => $post->id,
        ]);
    }

    // Owner-only edit — a post someone else wrote can't be silently
    // changed underneath them, so this re-checks author_id server-side
    // rather than trusting the client to only ever show the button on
    // its own posts.
    public function update(Request $request, string $id): JsonResponse
    {
        $me = $this->currentUser();
        $post = FeedPost::where('id', $id)->where('author_id', $me->id)->first();
        if (! $post) {
            return $this->fail(404, 'POST_NOT_FOUND', 'Post not found.');
        }
        $text = (string) $request->input('text', $post->text);
        if (mb_strlen($text) > 2000) {
            return $this->fail(400, 'VALIDATION', 'Text must be at most 2000 characters.');
        }
        if ($blocked = ModerationService::checkText($me, $text)) {
            return $this->fail(400, 'CONTENT_BLOCKED', $blocked);
        }

        $post->update([
            'text' => $text,
            'visibility' => SocialAudience::visibility(
                $request->input('visibility'),
                $post->visibility ?: 'everyone',
            ),
        ]);
        $this->announceFeedChange(['feed'], 'updated', $post->id);

        return $this->ok($this->postToJson($this->reload($post->id, $me)));
    }

    public function destroy(string $id): JsonResponse
    {
        $me = $this->currentUser();
        $post = FeedPost::where('id', $id)->where('author_id', $me->id)->first();
        if (! $post) {
            return $this->fail(404, 'POST_NOT_FOUND', 'Post not found.');
        }
        $post->delete();
        $this->announceFeedChange(['feed'], 'deleted', $id);

        return $this->ok(['deleted' => true]);
    }

    public function storeOfficial(Request $request): JsonResponse
    {
        $me = $this->currentUser();
        $text = (string) $request->input('text', '');
        if ($blocked = ModerationService::checkText($me, $text)) {
            return $this->fail(400, 'CONTENT_BLOCKED', $blocked);
        }
        if (mb_strlen($text) > 2000) {
            return $this->fail(400, 'VALIDATION', 'Text must be at most 2000 characters.');
        }
        $attachment = SocialMediaAttachment::resolve($request->input('imageUrl'), $me, false);
        if ($attachment['code'] !== null) {
            return $this->fail(422, $attachment['code'], $attachment['message']);
        }

        $row = [
            'id' => $this->newId('post'),
            'author_id' => $me->id,
            'name' => $me->name,
            'text' => $text,
            'meta' => 'resmi duyuru',
            'image_url' => $attachment['url'],
            'media_mime_type' => $attachment['item']?->mime_type,
            'visibility' => 'everyone',
            'post_type' => $request->input('postType', 'normal'),
            'course_tag' => $request->input('courseTag'),
            'location_tag' => $request->input('locationTag'),
            'official' => true,
            'created_at' => now(),
        ];
        if (SchemaColumnCache::hasColumn('feed_posts', 'workflow_status')) {
            $row['workflow_status'] = 'published';
        }
        $post = FeedPost::create($row);
        $this->announceFeedChange(['feed'], 'created', $post->id);

        return $this->ok($this->postToJson($this->reload($post->id, $me)), 201);
    }

    public function pin(string $id): JsonResponse
    {
        $me = $this->currentUser();
        $post = FeedPost::find($id);
        if (! $post || ! $post->official) {
            return $this->fail(404, 'POST_NOT_FOUND', 'Only official posts can be pinned.');
        }

        if (! $post->is_pinned) {
            $pinned = FeedPost::where('is_pinned', true)->orderBy('pinned_at')->orderBy('id')->get();
            if ($pinned->count() >= 3) {
                $oldest = $pinned->first();
                $oldest->update([
                    'is_pinned' => false,
                    'pinned_at' => null,
                    'pinned_by' => null,
                ]);
            }
        }

        $post->update([
            'is_pinned' => true,
            'pinned_at' => now(),
            'pinned_by' => $me->id,
        ]);
        $this->announceFeedChange(['feed'], 'pinned', $post->id);

        return $this->ok($this->postToJson($this->reload($post->id, $me)));
    }

    public function unpin(string $id): JsonResponse
    {
        $me = $this->currentUser();
        $post = FeedPost::find($id);
        if (! $post) {
            return $this->fail(404, 'POST_NOT_FOUND', 'Post not found.');
        }
        $post->update([
            'is_pinned' => false,
            'pinned_at' => null,
            'pinned_by' => null,
        ]);
        $this->announceFeedChange(['feed'], 'unpinned', $post->id);

        return $this->ok($this->postToJson($this->reload($post->id, $me)));
    }

    /** @param list<string> $resources */
    private function announceFeedChange(array $resources, string $action, string $id): void
    {
        try {
            // The websocket only tells clients what collection to reload; it
            // never bypasses visibility, block or moderation checks in GET
            // /feed.
            broadcast(new CampusDataChanged($resources, $action, $id));
        } catch (\Throwable) {
            // A Reverb outage must not turn a persisted social action into a
            // failed user interaction.
        }
    }

    private function constrainPrivateAuthors(Builder $query, User $me): void
    {
        if (GranularPermissions::allows($me, 'users.manage')
            || GranularPermissions::allows($me, 'moderation.moderate')) {
            return;
        }

        $query->where(function ($q) use ($me) {
            $q->where('author_id', $me->id)
                ->orWhereDoesntHave('user', fn ($u) => $u->where('is_private_profile', true))
                ->orWhereHas('user', function ($u) use ($me) {
                    $u->where('is_private_profile', true)
                        ->whereHas('followers', fn ($f) => $f->where('users.id', $me->id));
                });
        });
    }

    public function report(Request $request, string $id): JsonResponse
    {
        $me = $this->currentUser();
        $post = $this->visiblePost($id, $me);
        if (! $post) {
            return $this->fail(404, 'POST_NOT_FOUND', 'Post not found.');
        }
        $reason = (string) $request->input('reason', '');

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

    private function visiblePost(string $id, User $me): ?FeedPost
    {
        $post = FeedPost::find($id);
        if (! $post) {
            return null;
        }
        $status = $post->workflow_status ?? 'published';
        if ($status === 'rejected') {
            return null;
        }
        if ($status === 'pending_review'
            && (int) $post->author_id !== (int) $me->id
            && ! GranularPermissions::allows($me, 'moderation.moderate')) {
            return null;
        }
        if (! SocialAudience::mayView($post->visibility, (int) $post->author_id, $me, (bool) $post->official)) {
            return null;
        }
        $author = $post->user;
        if ($author && ! \App\Services\PrivateProfileGate::allows($me, $author)) {
            return null;
        }

        return $post;
    }
}
