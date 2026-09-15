<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\ClubMember;
use App\Models\FeedPost;
use App\Models\ModerationEvent;
use App\Models\PolicyConsent;
use App\Models\Story;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Deleting your own account.
 *
 * Both stores require this of anything that lets you create an account —
 * Apple 5.1.1(v), Google Play's data-deletion policy — and neither accepts
 * "email us and we will do it". There was no way to do it at all.
 *
 * The thing worth testing is not that the row disappears; it is what goes
 * *with* it. A deletion that leaves a student's posts on the feed under a
 * deleted name is worse than no deletion at all, because it looks like it
 * worked.
 */
class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse';

    private function account(string $email = 'leaver@arucad.edu.tr'): User
    {
        return User::create([
            'name' => 'Leaving Student',
            'email' => $email,
            'password' => bcrypt(self::PASSWORD),
        ]);
    }

    private function withContent(User $user): void
    {
        FeedPost::create([
            'id' => 'post-'.$user->id,
            'author_id' => $user->id,
            'name' => $user->name,
            'text' => 'something',
            'meta' => '',
            'created_at' => now(),
        ]);

        Story::create([
            'id' => 'story-'.$user->id,
            'author_id' => $user->id,
            'author_name' => $user->name,
            'text' => 'a story',
            'created_at' => now(),
        ]);
    }

    // ---- it has to be possible at all ------------------------------------

    public function test_a_student_can_delete_their_own_account(): void
    {
        $user = $this->account();
        $this->actingAsUser($user);

        $this->postJson('/api/v1/me/delete', ['password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        // Not `withTrashed()`: accounts are deliberately hard-deleted, so
        // that the schema's cascades fire and the person's content goes
        // with them.
        $this->assertNull(User::find($user->id));
    }

    /**
     * The confirmation screen has to be able to say what will happen.
     */
    public function test_the_preview_says_what_will_be_removed(): void
    {
        $user = $this->account();
        $this->withContent($user);
        $this->actingAsUser($user);

        $this->getJson('/api/v1/me/deletion-preview')
            ->assertOk()
            ->assertJsonPath('data.removes.posts', 1)
            ->assertJsonPath('data.removes.stories', 1)
            ->assertJsonPath('data.email', $user->email);
    }

    // ---- what goes with it ------------------------------------------------

    /**
     * The whole point. Deletion is a cascade, which is also why accounts
     * are deliberately not soft-deleted: a soft delete issues no DELETE, so
     * none of these would fire.
     */
    public function test_the_students_content_goes_with_the_account(): void
    {
        $user = $this->account();
        $this->withContent($user);

        Club::create([
            'id' => 'club-x',
            'name' => 'Test Club',
            'category' => 'social',
        ]);
        ClubMember::create([
            'user_id' => $user->id,
            'club_id' => 'club-x',
            'created_at' => now(),
        ]);

        $this->actingAsUser($user);
        $this->postJson('/api/v1/me/delete', ['password' => self::PASSWORD])->assertOk();

        $this->assertSame(0, FeedPost::withoutGlobalScopes()->count());
        $this->assertSame(0, Story::withoutGlobalScopes()->count());
        $this->assertSame(0, DB::table('club_members')->count());
    }

    public function test_consent_records_go_with_the_account(): void
    {
        $user = $this->account();
        $this->actingAsUser($user);

        $this->postJson('/api/v1/me/policy-consent', ['locale' => 'tr'])->assertOk();
        $this->assertSame(1, PolicyConsent::count());

        $this->postJson('/api/v1/me/delete', ['password' => self::PASSWORD])->assertOk();

        $this->assertSame(0, PolicyConsent::count());
    }

    /**
     * A student deleting their account from a phone must not leave a laptop
     * signed in.
     */
    public function test_every_session_is_revoked(): void
    {
        $user = $this->account();
        $laptop = $user->createToken('laptop')->plainTextToken;

        $this->assertSame(1, $user->tokens()->count());

        $this->actingAsUser($user);
        $this->postJson('/api/v1/me/delete', ['password' => self::PASSWORD])->assertOk();

        // Asserted against the token table rather than a second HTTP call:
        // `Sanctum::actingAs` installs a guard that returns the user
        // whatever token is presented, so a request made under it can never
        // show a token being rejected and the test would pass either way.
        $this->assertSame(
            0,
            DB::table('personal_access_tokens')
                ->where('tokenable_id', $user->id)
                ->count(),
            'A laptop would still be signed in after the phone deleted the account.',
        );
    }

    // ---- what deliberately survives --------------------------------------

    /**
     * Disclosed in the privacy policy, and load-bearing: without it the
     * university cannot answer an appeal or a disciplinary question about
     * content it has already removed.
     */
    public function test_moderation_records_survive_the_deletion(): void
    {
        $user = $this->account();

        ModerationEvent::create([
            'id' => (string) Str::uuid(),
            'user_id' => (string) $user->id,
            'content_type' => 'text',
            'source_feature' => 'feed',
            'action' => 'blocked',
            'flagged' => true,
            'created_at' => now(),
        ]);

        $this->actingAsUser($user);
        $this->postJson('/api/v1/me/delete', ['password' => self::PASSWORD])->assertOk();

        $this->assertSame(1, ModerationEvent::count());
    }

    public function test_the_deletion_is_recorded_before_the_row_goes(): void
    {
        $user = $this->account();
        $this->actingAsUser($user);

        $this->postJson('/api/v1/me/delete', ['password' => self::PASSWORD])->assertOk();

        $this->assertDatabaseHas('admin_audit_log', [
            'actor_name' => $user->email,
            'action' => 'delete',
            'target_type' => 'own_account',
        ]);
    }

    // ---- it must not be easy to do to somebody else ----------------------

    /**
     * A valid token can be a phone left unlocked on a table, and this is
     * the one action in the app with no undo.
     */
    public function test_the_password_is_required_again(): void
    {
        $user = $this->account();
        $this->actingAsUser($user);

        $this->postJson('/api/v1/me/delete', [])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'PASSWORD_REQUIRED');

        $this->assertNotNull(User::find($user->id));
    }

    public function test_a_wrong_password_does_not_delete_anything(): void
    {
        $user = $this->account();
        $this->withContent($user);
        $this->actingAsUser($user);

        $this->postJson('/api/v1/me/delete', ['password' => 'not-it'])
            ->assertStatus(403);

        $this->assertNotNull(User::find($user->id));
        $this->assertSame(1, FeedPost::withoutGlobalScopes()->count());
    }

    public function test_deletion_requires_a_signed_in_account(): void
    {
        $this->postJson('/api/v1/me/delete', ['password' => self::PASSWORD])
            ->assertStatus(401);
        $this->getJson('/api/v1/me/deletion-preview')->assertStatus(401);
    }

    /**
     * One account's deletion must not touch another's.
     */
    public function test_only_the_signed_in_account_is_deleted(): void
    {
        $me = $this->account();
        $other = $this->account('stays@arucad.edu.tr');
        $this->withContent($other);

        $this->actingAsUser($me);
        $this->postJson('/api/v1/me/delete', ['password' => self::PASSWORD])->assertOk();

        $this->assertNotNull(User::find($other->id));
        $this->assertSame(1, FeedPost::withoutGlobalScopes()->count());
    }
}
