<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Api\Concerns\ModeratesContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertClubRequest;
use App\Models\Club;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Public read (/clubs) + admin write (/admin/clubs) live in one
// controller, same pattern as PlaceController — Club has no separate
// admin-only concerns beyond who's allowed to call the write routes,
// which real RBAC middleware will gate once real multi-user auth exists
// (see docs/EKSIKLER.md §1/§11).
class ClubController extends Controller
{
    use ApiResponds, ModeratesContent;

    private function toJson(Club $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'category' => $c->category,
            'description' => $c->description,
            'body' => $c->body ?? [],
            'memberCount' => (int) ($c->members_count ?? $c->members()->count()),
            'responsibleStaffId' => $c->responsible_staff_id,
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $query = Club::query()->withCount('members');
        if ($category = $request->query('category')) {
            $query->where('category', $category);
        }

        return $this->ok($query->get()->map(fn ($c) => $this->toJson($c)));
    }

    public function show(string $id): JsonResponse
    {
        $club = Club::withCount('members')->find($id);
        if (! $club) {
            return $this->fail(404, 'CLUB_NOT_FOUND', 'Club not found.');
        }

        return $this->ok($this->toJson($club));
    }

    public function upsert(UpsertClubRequest $request): JsonResponse
    {
        if ($blocked = $this->moderationBlock(
            $this->currentUser(),
            $this->moderationText($request->safe()->only(['name', 'description', 'body'])),
            'catalog',
            'admin.club.upsert',
        )) {
            return $blocked;
        }
        $id = $request->input('id');
        $name = $request->input('name');

        $isNew = ! Club::where('id', $id)->exists();
        $club = Club::updateOrCreate(['id' => $id], [
            'name' => $name,
            'category' => $request->input('category', ''),
            'description' => $request->input('description', ''),
            'body' => $request->input('body', []),
            'responsible_staff_id' => $request->input('responsibleStaffId'),
        ]);
        AuditLogger::logAsCurrentUser($isNew ? 'create' : 'update', 'club', $name);

        return $this->ok($this->toJson($club));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $club = Club::find($id);
        if ($club) {
            AuditLogger::logAsCurrentUser('delete', 'club', $club->name);
            $club->delete();
        }

        return $this->ok(['deleted' => true]);
    }
}
