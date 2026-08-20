<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\FeedPost;
use App\Models\ModerationReport;
use App\Models\PostComment;
use App\Services\ActivityLogger;
use App\Services\ModerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FeedController extends Controller
{
    use ApiResponds;

    private function postToJson(FeedPost $p): array
    {
        return [
            'id' => $p->id,
            'authorId' => $p->author_id,
            'name' => $p->name,
            'text' => $p->text,
            'meta' => $p->meta,
            'likes' => $p->likes,
            'likedByMe' => $p->liked_by_me,
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
        $posts = FeedPost::with('comments')->orderByDesc('created_at')->get();

        return $this->ok($posts->map(fn ($p) => $this->postToJson($p)));
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
            'liked_by_me' => false,
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

        return $this->ok($this->postToJson($post->fresh('comments')));
    }

    public function like(string $id): JsonResponse
    {
        $post = FeedPost::find($id);
        if (! $post) {
            return $this->fail(404, 'POST_NOT_FOUND', 'Post not found.');
        }
        $nowLiked = ! $post->liked_by_me;
        $post->liked_by_me = $nowLiked;
        $post->likes = max(0, $post->likes + ($nowLiked ? 1 : -1));
        $post->save();

        if ($nowLiked) {
            ActivityLogger::log($this->currentUser()->id, 'like', "Beğendin: {$post->name}", $post->text);
        }

        return $this->ok($this->postToJson($post->fresh('comments')));
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

        return $this->ok($this->postToJson($post->fresh('comments')));
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
