<?php

namespace Tests\Feature;

use App\Models\Club;
use App\Models\Place;
use App\Models\User;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Delete, in the panels, means "take this out of the app" — not "destroy
 * it".
 *
 * A member of staff working through a 300-row table will eventually delete
 * the wrong row. Before soft deletes the only recovery was a database
 * restore, which would also roll back every other change since the backup.
 *
 * The line between what is soft-deleted and what is not is drawn on
 * purpose, and these tests hold it:
 *
 *   - campus content is soft-deleted, because a mis-click there costs work
 *     and nothing else;
 *   - accounts are not, because deleting one is supposed to erase the
 *     person's posts, stories and memberships through the schema's
 *     cascades, and a soft delete never fires them.
 */
class SoftDeleteRestoreTest extends TestCase
{
    use RefreshDatabase;

    private function place(string $id = 'place-soft-delete'): Place
    {
        return Place::create([
            'id' => $id,
            'name' => 'Test Studio',
            'category' => 'academic',
            'lat' => 35.33,
            'lng' => 33.31,
        ]);
    }

    public function test_deleted_content_disappears_from_reads(): void
    {
        $this->place()->delete();

        $this->assertNull(Place::find('place-soft-delete'));
    }

    public function test_deleted_content_comes_back_on_restore(): void
    {
        $this->place()->delete();

        Place::withTrashed()->find('place-soft-delete')->restore();

        $this->assertNotNull(Place::find('place-soft-delete'));
    }

    /**
     * A restore screen can only offer what a query can find.
     */
    public function test_deleted_content_is_still_reachable_for_a_restore_screen(): void
    {
        $this->place('place-trashed')->delete();

        $this->assertCount(1, Place::onlyTrashed()->get());
    }

    /**
     * Deleting is reversible; purging is the one that is not, and it is
     * what makes a deletion final when someone means it.
     */
    public function test_force_deleting_content_really_removes_the_row(): void
    {
        $this->place('place-purged')->forceDelete();

        $this->assertCount(0, Place::withTrashed()->where('id', 'place-purged')->get());
    }

    /**
     * Deleted content must not reappear through a listing that forgot to
     * scope itself — the failure mode is a student tapping a building that
     * no longer exists.
     */
    public function test_deleted_content_is_gone_from_the_public_api(): void
    {
        $this->actingAsUser();
        $this->place('place-public');

        $before = $this->getJson('/api/v1/places')->assertOk()->json('data');
        $this->assertContains('place-public', array_column($before, 'id'));

        Place::find('place-public')->delete();

        $after = $this->getJson('/api/v1/places')->assertOk()->json('data');
        $this->assertNotContains('place-public', array_column($after, 'id'));
    }

    /**
     * The guard on the line this design depends on.
     *
     * Adding `SoftDeletes` to `User` looks harmless and passes its own
     * tests, but it silently stops `ON DELETE CASCADE` from firing: the
     * account vanishes from the admin listing while the student's posts,
     * stories, club memberships and onboarding progress stay in the app.
     * Reversible lockout is what ban/enforcement is for.
     */
    public function test_accounts_are_not_soft_deleted(): void
    {
        $this->assertNotContains(
            SoftDeletes::class,
            class_uses_recursive(User::class),
            'Deleting an account has to keep cascading to the content it owns.'
        );
    }

    public function test_campus_content_models_are_soft_deleted(): void
    {
        foreach ([Place::class, Club::class] as $model) {
            $this->assertContains(
                SoftDeletes::class,
                class_uses_recursive($model),
                $model.' lost its soft delete, so the panel cannot undo a mis-click.'
            );
        }
    }
}
