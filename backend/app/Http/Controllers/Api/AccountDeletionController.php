<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Deleting your own account, from inside the app.
 *
 * Both stores require this of anything that lets you create an account —
 * Apple guideline 5.1.1(v) and Google Play's data-deletion policy — and
 * neither accepts "email us and we will do it". There was no way to do it
 * at all, which is a rejection on submission and, more to the point, a
 * student who wants their content gone having no way to make that happen.
 *
 * What deletion actually removes is decided by the schema, not by code
 * here: 39 foreign keys cascade from `users`, so a student's posts,
 * stories, comments, likes, club memberships, onboarding progress, saved
 * posts, push tokens and consent records all go with the row. That is also
 * why accounts are deliberately *not* soft-deleted — a soft delete issues
 * no DELETE, so none of those cascades would fire and the person's content
 * would stay on the feed under a deleted name.
 *
 * What survives is deliberate and disclosed: moderation events, reports and
 * appeals reference the user by a plain column with no foreign key, so the
 * record of decisions taken about someone outlives their account. The
 * privacy policy says so ("Some records may be retained after account
 * deletion when required for safety, disciplinary, or legal purposes"), and
 * without it the university could not answer an appeal or a disciplinary
 * question about content it had already removed.
 */
class AccountDeletionController extends Controller
{
    use ApiResponds;

    /**
     * What will happen, so the confirmation screen can say it plainly
     * rather than making the student guess.
     */
    public function preview(): JsonResponse
    {
        $me = $this->currentUser();

        return $this->ok([
            'email' => $me->email,
            'removes' => [
                'posts' => DB::table('feed_posts')->where('author_id', $me->id)->count(),
                'stories' => DB::table('stories')->where('author_id', $me->id)->count(),
                'comments' => DB::table('post_comments')->where('user_id', $me->id)->count(),
                'clubMemberships' => DB::table('club_members')->where('user_id', $me->id)->count(),
                'media' => DB::table('media_items')->where('user_id', $me->id)->count(),
            ],
            // Named rather than hidden. Someone deleting their account is
            // entitled to know what does not go with it.
            'retains' => [
                'moderationRecords' => DB::table('moderation_events')
                    ->where('user_id', (string) $me->id)
                    ->count(),
            ],
            'retentionReason' => 'Safety, disciplinary and legal records are kept '
                .'as described in the Privacy Policy.',
        ]);
    }

    /**
     * Deletes the signed-in account.
     *
     * The password is required again even though the caller already holds a
     * valid token. A token can be a phone somebody left unlocked on a
     * table, and this is the one action in the app with no undo.
     */
    public function destroy(Request $request): JsonResponse
    {
        $me = $this->currentUser();

        $password = (string) $request->input('password', '');

        if ($password === '' || ! Hash::check($password, (string) $me->password)) {
            return $this->fail(
                403,
                'PASSWORD_REQUIRED',
                'Hesabınızı silmek için şifrenizi doğrulamanız gerekir.',
            );
        }

        // Written before the deletion, not after: once the row is gone
        // there is no id left to attribute anything to, and this entry is
        // the only remaining evidence that the account existed and asked to
        // be removed. It records the act, not the person's content.
        AuditLogger::log($me->email, 'delete', 'own_account', 'self-service deletion');

        Log::info('account.self_deleted', ['user_id' => $me->id]);

        DB::transaction(function () use ($me) {
            // Every token, not just this one: a student deleting their
            // account from a phone must not leave a laptop signed in.
            $me->tokens()->delete();

            // The cascades do the rest.
            $me->delete();
        });

        return $this->ok(['deleted' => true]);
    }
}
