<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\FeedPost;
use App\Models\ModerationReport;
use App\Models\Notification as InboxNotification;
use App\Models\PostComment;
use App\Models\PostLike;
use App\Services\ActivityLogger;
use App\Services\ModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FeedController extends Controller
{
    use ApiResponds;

    // Real bug fix (docs/EKSIKLER.md sosyal §1): likedByMe used to be a
    // single boolean column on the post itself, shared by every account —
    // one real user liking a post made it show as "liked" for every other
    // real account too. It's now computed per viewer from the real
    // post_likes table (see PostLike), the same way comments already are.
    private function postToJson(FeedPost $p, ?string $viewerUserId): array
    {
        return [
            'id' => $p->id,
            'authorId' => $p->author_id,
            'name' => $p->name,
            'text' => $p->text,
            'meta' => $p->meta,
            'likes' => $p->likes,
            'likedByMe' => $viewerUserId !== null
                && PostLike::where('post_id', $p->id)->where('user_id', $viewerUserId)->exists(),
            'imageUrl' => $p->image_url,
            'comments' => $p->comments->map(fn ($c) => [
                'id' => $c->id,
                'author' => $c->author,
                'text' => $c->text,
                'meta' => $c->meta,
            ]),
            'visibility' => $p->visibility,
            'postType' => $p->post_type,
            'courseTag' => $p->course_tag,
            'locationTag' => $p->location_tag,
            'official' => $p->official,
        ];
    }

    public function index(): JsonResponse
    {
        $me = $this->currentUser();
        $posts = FeedPost::with('comments')->orderByDesc('created_at')->get();

        return $this->ok($posts->map(fn ($p) => $this->postToJson($p, (string) $me->id)));
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
            'author_id' => (string) $me->id,
            'name' => $me->name,
            'text' => $text,
            'meta' => 'az önce',
            'likes' => 0,
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

        return $this->ok($this->postToJson($post->fresh('comments'), (string) $me->id));
    }

    public function like(string $id): JsonResponse
    {
        $post = FeedPost::find($id);
        if (! $post) {
            return $this->fail(404, 'POST_NOT_FOUND', 'Post not found.');
        }
        $me = $this->currentUser();

        $existing = PostLike::where('post_id', $post->id)->where('user_id', $me->id)->first();
        $nowLiked = ! $existing;
        if ($nowLiked) {
            PostLike::create([
                'id' => $this->newId('like'),
                'post_id' => $post->id,
                'user_id' => $me->id,
                'created_at' => now(),
            ]);
        } else {
            $existing->delete();
        }
        $post->likes = max(0, $post->likes + ($nowLiked ? 1 : -1));
        $post->save();

        if ($nowLiked) {
            ActivityLogger::log($me->id, 'like', "Beğendin: {$post->name}", $post->text);
            // Real notification to the post's real author — not the liker
            // (matches the chat/follow pattern: resolve by author_id, a
            // real user id, so this is genuinely per-account, not simulated).
            if ($post->author_id && $post->author_id !== (string) $me->id) {
                InboxNotification::create([
                    'id' => 'notif-'.Str::uuid(),
                    'user_id' => $post->author_id,
                    'kind' => 'like',
                    'title' => 'Yeni beğeni',
                    'body' => "{$me->name} gönderini beğendi.",
                    'created_at' => now(),
                ]);
            }
        }

        return $this->ok($this->postToJson($post->fresh('comments'), (string) $me->id));
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

        PostComment::create([
            'id' => $this->newId('comment'),
            'post_id' => $post->id,
            'author' => $me->name,
            'text' => $text,
            'meta' => 'az önce',
            'created_at' => now(),
        ]);
        ActivityLogger::log($me->id, 'comment', "Yorum yaptın: {$post->name}", $text);

        if ($post->author_id && $post->author_id !== (string) $me->id) {
            InboxNotification::create([
                'id' => 'notif-'.Str::uuid(),
                'user_id' => $post->author_id,
                'kind' => 'comment',
                'title' => 'Yeni yorum',
                'body' => "{$me->name}: {$text}",
                'created_at' => now(),
            ]);
        }

        return $this->ok($this->postToJson($post->fresh('comments'), (string) $me->id));
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
