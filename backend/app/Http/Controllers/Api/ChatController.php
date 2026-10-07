<?php

namespace App\Http\Controllers\Api;

use App\Events\MessageCreated;
use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Api\Concerns\ModeratesContent;
use App\Http\Controllers\Api\Concerns\SubmitsReports;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendChatMessageRequest;
use App\Models\ChatGroup;
use App\Models\ChatGroupMessage;
use App\Models\ChatMessage;
use App\Models\ChatThreadPref;
use App\Models\Conversation;
use App\Models\Notification as InboxNotification;
use App\Models\SocialBlock;
use App\Models\User;
use App\Services\ConversationService;
use App\Support\MediaPublicUrl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// Shared 1:1 threads: one conversation per unordered user pair, one
// message row per send, sender_id = the authenticated user. Display names
// are resolved at the edge and never stored as the relation. REST remains
// the history source of truth; MessageCreated is realtime delivery only.
class ChatController extends Controller
{
    use ApiResponds, ModeratesContent, SubmitsReports;

    public function __construct(private ConversationService $conversations) {}

    public function threads(): JsonResponse
    {
        $me = $this->currentUser();
        $blockedPeerIds = $this->blockedPeerIds($me);
        $prefsByPeer = ChatThreadPref::query()
            ->where('user_id', $me->id)
            ->get()
            ->keyBy(fn (ChatThreadPref $p) => (int) $p->peer_user_id);

        $peers = Conversation::query()
            ->whereHas('participants', fn ($q) => $q->where('users.id', $me->id))
            ->with('participants')
            ->get()
            ->map(function (Conversation $conversation) use ($me, $prefsByPeer, $blockedPeerIds) {
                $peer = $conversation->otherParticipant($me->id);
                if ($peer === null || in_array((int) $peer->id, $blockedPeerIds, true)) {
                    return null;
                }
                $pref = $prefsByPeer->get((int) $peer->id);
                $last = $conversation->messages()->latest('created_at')->latest('id')->first();
                $unread = $conversation->messages()
                    ->where('sender_id', $peer->id)
                    ->when($pref?->last_read_at !== null,
                        fn ($q) => $q->where('created_at', '>', $pref->last_read_at))
                    ->count();

                return [
                    'name' => $peer->name,
                    'avatarUrl' => MediaPublicUrl::rewrite($peer->avatar_url),
                    'lastMessage' => $last?->body,
                    'lastMessageAt' => $last?->created_at?->toIso8601String(),
                    'unreadCount' => $unread,
                    'muted' => $pref?->muted_at !== null,
                    'archived' => $pref?->archived_at !== null,
                    'restricted' => $pref?->restricted_at !== null,
                ];
            })
            ->filter()
            ->sortByDesc('lastMessageAt')
            ->values();

        return $this->ok($peers);
    }

    public function prefs(): JsonResponse
    {
        $me = $this->currentUser();
        $rows = ChatThreadPref::query()
            ->where('user_id', $me->id)
            ->with('peer')
            ->orderByDesc('updated_at')
            ->get();

        return $this->ok($rows->map(fn (ChatThreadPref $pref) => [
            'peer' => $pref->peer?->name,
            'muted' => $pref->muted_at !== null,
            'archived' => $pref->archived_at !== null,
            'restricted' => $pref->restricted_at !== null,
        ])->values());
    }

    public function togglePref(Request $request): JsonResponse
    {
        $me = $this->currentUser();
        $target = $this->resolvePeerFromRequest($request);
        if ($target instanceof JsonResponse) {
            return $target;
        }
        if ($target->id === $me->id) {
            return $this->fail(400, 'VALIDATION', 'You cannot set prefs on yourself.');
        }

        $field = strtolower((string) $request->input('field', ''));
        $column = match ($field) {
            'mute' => 'muted_at',
            'archive' => 'archived_at',
            'restrict' => 'restricted_at',
            default => null,
        };
        if ($column === null) {
            return $this->fail(400, 'VALIDATION', 'field must be mute, archive, or restrict.');
        }

        $pref = ChatThreadPref::query()->firstOrNew([
            'user_id' => $me->id,
            'peer_user_id' => $target->id,
        ]);
        $pref->{$column} = $pref->{$column} === null ? now() : null;
        $pref->save();

        return $this->ok(array_merge([
            'peer' => $target->name,
        ], $pref->toStateArray()));
    }

    public function groups(): JsonResponse
    {
        $me = $this->currentUser();
        $groups = ChatGroup::query()
            ->whereHas('members', fn ($q) => $q->where('users.id', $me->id))
            ->with(['members' => fn ($q) => $q->orderBy('name')])
            ->orderByDesc('updated_at')
            ->get();

        return $this->ok($groups->map(function (ChatGroup $g) use ($me) {
            $mine = $g->members->firstWhere('id', $me->id);

            return [
                'id' => $g->id,
                'name' => $g->name,
                'createdBy' => (string) $g->created_by,
                'muted' => $mine?->pivot?->muted_at !== null,
                'archived' => $mine?->pivot?->archived_at !== null,
                'members' => $g->members->map(fn (User $u) => [
                    'id' => (string) $u->id,
                    'name' => $u->name,
                    'avatarUrl' => MediaPublicUrl::rewrite($u->avatar_url),
                ])->values(),
            ];
        })->values());
    }

    public function createGroup(Request $request): JsonResponse
    {
        $me = $this->currentUser();
        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return $this->fail(400, 'VALIDATION', 'name is required.');
        }
        // A group name is shown to everyone invited, so it is public text.
        if ($blocked = $this->moderationBlock($me, $name, 'group_name', 'chat.createGroup')) {
            return $blocked;
        }

        $memberIds = collect($request->input('memberIds', $request->input('member_ids', [])))
            ->filter(fn ($id) => $id !== null && $id !== '')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $memberNames = collect($request->input('memberNames', $request->input('member_names', [])))
            ->filter(fn ($n) => is_string($n) && trim($n) !== '')
            ->map(fn ($n) => trim($n))
            ->unique()
            ->values();

        foreach ($memberNames as $memberName) {
            $matches = User::query()->where('name', $memberName)->get();
            if ($matches->count() !== 1) {
                return $this->fail(
                    $matches->isEmpty() ? 404 : 400,
                    $matches->isEmpty() ? 'USER_NOT_FOUND' : 'VALIDATION',
                    $matches->isEmpty()
                        ? "Member not found: {$memberName}"
                        : "Member name is ambiguous: {$memberName}",
                );
            }
            $memberIds->push((int) $matches->first()->id);
        }

        $memberIds = $memberIds->unique()->reject(fn (int $id) => $id === (int) $me->id)->values();
        if ($memberIds->isEmpty()) {
            return $this->fail(400, 'VALIDATION', 'At least one other member is required.');
        }

        $users = User::query()->whereIn('id', $memberIds)->get();
        if ($users->count() !== $memberIds->count()) {
            return $this->fail(404, 'USER_NOT_FOUND', 'One or more members were not found.');
        }

        $group = DB::transaction(function () use ($me, $name, $users) {
            $group = ChatGroup::create([
                'id' => $this->newId('gchat'),
                'name' => $name,
                'created_by' => $me->id,
            ]);
            $ids = $users->pluck('id')->all();
            $ids[] = $me->id;
            $group->members()->sync(array_values(array_unique($ids)));

            return $group->load(['members' => fn ($q) => $q->orderBy('name')]);
        });

        return $this->ok([
            'id' => $group->id,
            'name' => $group->name,
            'createdBy' => (string) $group->created_by,
            'muted' => false,
            'archived' => false,
            'members' => $group->members->map(fn (User $u) => [
                'id' => (string) $u->id,
                'name' => $u->name,
                'avatarUrl' => MediaPublicUrl::rewrite($u->avatar_url),
            ])->values(),
        ], 201);
    }

    public function leaveGroup(string $id): JsonResponse
    {
        $me = $this->currentUser();
        $group = ChatGroup::query()->whereKey($id)->first();
        if (! $group || ! $group->members()->where('users.id', $me->id)->exists()) {
            return $this->fail(404, 'GROUP_NOT_FOUND', 'Group not found.');
        }

        $group->members()->detach($me->id);

        return $this->ok(['left' => true]);
    }

    public function toggleGroupPref(Request $request, string $id): JsonResponse
    {
        $me = $this->currentUser();
        $group = $this->memberGroupOrFail($id, $me);
        if ($group instanceof JsonResponse) {
            return $group;
        }

        $field = strtolower((string) $request->input('field', ''));
        $column = match ($field) {
            'mute' => 'muted_at',
            'archive' => 'archived_at',
            default => null,
        };
        if ($column === null) {
            return $this->fail(400, 'VALIDATION', 'field must be mute or archive.');
        }

        $member = $group->members()->where('users.id', $me->id)->first();
        $current = $member?->pivot?->{$column};
        $group->members()->updateExistingPivot($me->id, [
            $column => $current === null ? now() : null,
        ]);

        $fresh = $group->members()->where('users.id', $me->id)->first();

        return $this->ok([
            'id' => $group->id,
            'muted' => $fresh?->pivot?->muted_at !== null,
            'archived' => $fresh?->pivot?->archived_at !== null,
        ]);
    }

    public function reportGroup(Request $request, string $id): JsonResponse
    {
        $me = $this->currentUser();
        $group = $this->memberGroupOrFail($id, $me);
        if ($group instanceof JsonResponse) {
            return $group;
        }

        return $this->submitReport(
            request: $request,
            reporter: $me,
            targetType: 'chat_group',
            targetId: (string) $group->id,
            targetLabel: (string) $group->name,
            sourceFeature: 'chat.reportGroup',
            contentOwnerId: $group->created_by === null ? null : (int) $group->created_by,
        );
    }

    public function groupMessages(string $id): JsonResponse
    {
        $me = $this->currentUser();
        $group = $this->memberGroupOrFail($id, $me);
        if ($group instanceof JsonResponse) {
            return $group;
        }

        $rows = $group->messages()->with('sender')->orderBy('created_at')->orderBy('id')->get();

        return $this->ok($rows->map(fn (ChatGroupMessage $m) => $m->toApiArray())->values());
    }

    public function sendGroupMessage(Request $request, string $id): JsonResponse
    {
        $me = $this->currentUser();
        $group = $this->memberGroupOrFail($id, $me);
        if ($group instanceof JsonResponse) {
            return $group;
        }

        $text = trim((string) $request->input('text', ''));
        if ($text === '') {
            return $this->fail(400, 'VALIDATION', 'text is required.');
        }
        if ($blocked = $this->moderationBlock($me, $text, 'group_message', 'chat.sendGroupMessage')) {
            return $blocked;
        }

        $message = ChatGroupMessage::create([
            'id' => $this->newId('gmsg'),
            'group_id' => $group->id,
            'sender_user_id' => $me->id,
            'text' => $text,
            'created_at' => now(),
            'moderation_status' => 'approved',
        ]);
        $message->setRelation('sender', $me);
        $group->touch();

        return $this->ok($message->toApiArray());
    }

    public function messages(string $peer): JsonResponse
    {
        $me = $this->currentUser();
        $target = $this->peerOrFail($peer);
        if ($target instanceof JsonResponse) {
            return $target;
        }
        if ($target->id === $me->id) {
            return $this->fail(400, 'VALIDATION', 'You cannot open a chat with yourself.');
        }

        $conversation = $this->conversations->findPair($me, $target);
        if ($conversation === null) {
            return $this->ok([]);
        }
        if (! $conversation->hasParticipant($me->id)) {
            return $this->fail(403, 'FORBIDDEN', 'You are not a participant in this conversation.');
        }

        $rows = $conversation->messages()->with('sender')->orderBy('created_at')->orderBy('id')->get();

        ChatThreadPref::query()->updateOrCreate(
            ['user_id' => $me->id, 'peer_user_id' => $target->id],
            ['last_read_at' => now()],
        );

        return $this->ok($rows->map(fn (ChatMessage $m) => $m->toApiArray($me, $target))->values());
    }

    public function send(SendChatMessageRequest $request, string $peer): JsonResponse
    {
        $me = $this->currentUser();
        $target = $this->peerOrFail($peer);
        if ($target instanceof JsonResponse) {
            return $target;
        }
        if ($target->id === $me->id) {
            return $this->fail(400, 'VALIDATION', 'You cannot message yourself.');
        }
        if ($this->blockedPair($me, $target)) {
            return $this->fail(403, 'BLOCKED', 'You cannot message this user.');
        }

        $text = (string) $request->input('text');
        if ($blocked = $this->moderationBlock($me, $text, 'direct_message', 'chat.send')) {
            return $blocked;
        }

        $message = DB::transaction(function () use ($me, $target, $text) {
            $conversation = $this->conversations->findOrCreatePair($me, $target);
            $created = ChatMessage::create([
                'id' => 'msg-'.Str::uuid(),
                'conversation_id' => $conversation->id,
                'sender_id' => $me->id,
                'body' => $text,
                'moderation_status' => 'approved',
            ]);
            $created->setRelation('sender', $me);

            return $created;
        });

        InboxNotification::notify(
            $target,
            $me,
            'message',
            'Yeni mesaj',
            "{$me->name} sana bir mesaj gönderdi.",
            [
                'conversationId' => (string) $message->conversation_id,
                'messageId' => $message->id,
                'peer' => $me->name,
            ],
        );

        try {
            broadcast(new MessageCreated($message, $me, $target));
        } catch (\Throwable) {
            // Reverb down must not fail REST send; history is the source of truth.
        }

        return $this->ok($message->toApiArray($me, $target));
    }

    private function resolvePeerFromRequest(Request $request): User|JsonResponse
    {
        $peerId = $request->input('userId', $request->input('user_id', $request->input('peerId', $request->input('peer_id'))));
        if ($peerId !== null && $peerId !== '') {
            $user = User::query()->whereKey($peerId)->first();

            return $user ?? $this->fail(404, 'USER_NOT_FOUND', 'Target user not found.');
        }

        $peer = $request->input('peer');
        if ($peer === null || $peer === '') {
            return $this->fail(400, 'VALIDATION', 'peer is required.');
        }

        return $this->peerOrFail((string) $peer);
    }

    private function memberGroupOrFail(string $id, User $me): ChatGroup|JsonResponse
    {
        $group = ChatGroup::query()->whereKey($id)->first();
        if (! $group) {
            return $this->fail(404, 'GROUP_NOT_FOUND', 'Group not found.');
        }
        if (! $group->members()->where('users.id', $me->id)->exists()) {
            return $this->fail(403, 'FORBIDDEN', 'You are not a member of this group.');
        }

        return $group;
    }

    private function peerOrFail(string $peer): User|JsonResponse
    {
        $resolved = $this->conversations->resolvePeer(urldecode($peer));
        if ($resolved === 'missing') {
            return $this->fail(404, 'USER_NOT_FOUND', 'Target user not found.');
        }
        if ($resolved === 'ambiguous') {
            return $this->fail(400, 'VALIDATION', 'peer is ambiguous.');
        }

        return $resolved;
    }

    private function blockedPair(User $me, User $target): bool
    {
        return SocialBlock::query()
            ->where(function ($q) use ($me, $target) {
                $q->where('blocker_user_id', $me->id)->where('blocked_user_id', $target->id);
            })
            ->orWhere(function ($q) use ($me, $target) {
                $q->where('blocker_user_id', $target->id)->where('blocked_user_id', $me->id);
            })
            ->exists();
    }

    /** @return int[] */
    private function blockedPeerIds(User $me): array
    {
        return SocialBlock::query()
            ->where(function ($q) use ($me) {
                $q->where('blocker_user_id', $me->id)
                    ->orWhere('blocked_user_id', $me->id);
            })
            ->get(['blocker_user_id', 'blocked_user_id'])
            ->map(fn (SocialBlock $row) => (int) $row->blocker_user_id === (int) $me->id
                ? (int) $row->blocked_user_id
                : (int) $row->blocker_user_id)
            ->unique()
            ->values()
            ->all();
    }
}
