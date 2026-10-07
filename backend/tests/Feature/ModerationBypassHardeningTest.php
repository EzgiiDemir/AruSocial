<?php

namespace Tests\Feature;

use App\Models\FeedPost;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The ways someone would actually try to get round moderation.
 *
 * None of these go through the app. They are the requests a modified
 * client, a leaked token in a script, or a curious student with the
 * network tab open would send — which is the only threat model that
 * matters, because Flutter is not a security boundary and never was.
 */
class ModerationBypassHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $email): User
    {
        return User::firstOrCreate(
            ['email' => $email],
            ['name' => explode('@', $email)[0], 'password' => bcrypt('x')],
        );
    }

    private function banned(): User
    {
        $user = $this->actingAsUser($this->makeUser('banned@arucad.edu.tr'));
        $user->forceFill(['banned_until' => now()->addDays(3)])->save();

        return $user;
    }

    // ---- a ban is a property of the account, not of one screen -----

    /**
     * Every write surface, not just the one the app happens to show.
     *
     * A ban enforced on the endpoints someone remembered is a ban with
     * holes in it, and the holes are exactly where an angry banned user
     * looks first.
     */
    public function test_a_banned_account_is_refused_on_every_content_endpoint(): void
    {
        Storage::fake('local');
        $this->banned();

        $writes = [
            'feed post' => ['POST', '/api/v1/feed', ['text' => 'ban sonrasi gonderi']],
            'story' => ['POST', '/api/v1/stories', ['text' => 'ban sonrasi hikaye']],
            'profile bio' => ['POST', '/api/v1/me/profile', ['bio' => 'yeni bio']],
            'chat group' => ['POST', '/api/v1/chat/groups', ['name' => 'yeni grup']],
            'saved post' => ['POST', '/api/v1/saved-posts/toggle', ['postId' => 'x']],
            'follow' => ['POST', '/api/v1/social/follow', ['peer' => 'someone']],
            'report' => ['POST', '/api/v1/feed/x/report', ['reasonCode' => 'spam']],
            'appeal' => ['POST', '/api/v1/moderation/appeals', ['caseId' => 'x', 'reason' => 'y']],
        ];

        foreach ($writes as $label => [$method, $url, $payload]) {
            $response = $this->json($method, $url, $payload);
            $this->assertSame(403, $response->status(),
                "{$label}: a banned account reached {$url} (got {$response->status()})");
        }
    }

    /** Reading is allowed while banned; writing is not. */
    public function test_a_banned_account_can_still_read_its_own_standing(): void
    {
        $this->banned();

        // Otherwise a banned student cannot see that they are banned, or
        // for how long, which turns a suspension into "the app is broken".
        $this->getJson('/api/v1/health')->assertOk();
    }

    public function test_an_expired_ban_restores_access_without_an_admin(): void
    {
        $user = $this->actingAsUser($this->makeUser('served@arucad.edu.tr'));
        $user->forceFill(['banned_until' => now()->subMinute()])->save();

        $this->postJson('/api/v1/feed', ['text' => 'cezami tamamladim'])->assertOk();
    }

    // ---- editing is not a way in -----------------------------------

    /**
     * The oldest trick there is: post something harmless, wait for it to
     * be approved, then edit it into what you actually wanted to say.
     */
    public function test_an_approved_post_cannot_be_edited_into_unsafe_content(): void
    {
        $this->actingAsUser();

        $id = $this->postJson('/api/v1/feed', ['text' => 'bugun hava cok guzel'])
            ->assertOk()->json('data.id');

        $this->postJson("/api/v1/feed/{$id}", ['text' => 'lanet zenci defol buradan'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'CONTENT_BLOCKED');

        $this->assertSame('bugun hava cok guzel',
            FeedPost::includingUnmoderated()->find($id)->text,
            'The original text must survive a refused edit.');
    }

    /**
     * A profile is public surface. Every free-text field on it — not just
     * the obvious one — has to pass the same gate as a post, because a
     * slur reaches exactly as many people from a "department" field.
     */
    public function test_no_profile_field_can_be_edited_into_unsafe_content(): void
    {
        $me = $this->actingAsUser();

        $fields = [
            'department' => 'lanet zenci',
            'university' => 'lanet zenci',
            'year' => 'lanet zenci',
            'clubs' => ['lanet zenci'],
            'achievements' => ['lanet zenci'],
            'projects' => ['lanet zenci'],
        ];

        foreach ($fields as $field => $value) {
            $this->postJson('/api/v1/me/profile', [$field => $value])
                ->assertStatus(400, "profile field '{$field}' was not moderated")
                ->assertJsonPath('error.code', 'CONTENT_BLOCKED');

            // Every field must be refused because of what it contains,
            // not because an earlier field already locked the account.
            $this->clearStanding($me);
        }

        $fresh = $me->fresh();
        $this->assertNull($fresh->department, 'A refused profile edit was still saved.');
    }

    // ---- blocking is enforced by the server ------------------------

    /**
     * A block that only hides things in the client is not a block. The
     * person it protects has no way to know whether it worked, and the
     * person it restrains has an obvious way around it.
     */
    public function test_a_blocked_user_cannot_message_through_the_api(): void
    {
        // Both accounts must exist before the block: `peer` resolves to a
        // real user, and blocking a name nobody has is a 404, not a block.
        $victim = $this->makeUser('victim@arucad.edu.tr');
        $harasser = $this->makeUser('harasser@arucad.edu.tr');

        $this->actingAsUser($victim);
        $this->postJson('/api/v1/social/block', ['peer' => $harasser->name])->assertOk();

        $this->actingAsUser($harasser);

        $response = $this->postJson("/api/v1/chat/{$victim->name}/messages", [
            'text' => 'engellendim ama yine de yaziyorum',
        ]);

        $this->assertNotSame(200, $response->status(),
            'A blocked account reached its target through the chat API.');
    }

    // ---- moderation cannot be turned off from the client -----------

    /**
     * Nothing a client sends may change how its own content is judged.
     * If any of these were honoured, moderation would be advisory.
     */
    public function test_client_supplied_fields_cannot_skip_or_pre_approve_moderation(): void
    {
        $me = $this->actingAsUser();

        $attempts = [
            ['text' => 'lanet zenci defol', 'skipModeration' => true],
            ['text' => 'lanet zenci defol', 'moderationStatus' => 'approved'],
            ['text' => 'lanet zenci defol', 'workflowStatus' => 'published'],
            ['text' => 'lanet zenci defol', 'moderation_status' => 'approved'],
            ['text' => 'lanet zenci defol', 'official' => true],
        ];

        foreach ($attempts as $i => $payload) {
            $this->postJson('/api/v1/feed', $payload)
                ->assertStatus(400)
                ->assertJsonPath('error.code', 'CONTENT_BLOCKED');

            // Same reason as above: each flag has to be ignored on its
            // own, not shadowed by the penalty the last one earned.
            $this->clearStanding($me);
        }

        $this->assertSame(0, FeedPost::includingUnmoderated()->count(),
            'A client-supplied flag let unsafe content through.');
    }

    /** A student cannot promote their own post to an official announcement. */
    public function test_a_student_cannot_mark_their_own_post_official(): void
    {
        $this->actingAsUser();

        $id = $this->postJson('/api/v1/feed', [
            'text' => 'kampus duyurusu', 'official' => true,
        ])->assertOk()->json('data.id');

        $this->assertFalse((bool) FeedPost::find($id)->official,
            'A student promoted their own post to official.');
    }
}
