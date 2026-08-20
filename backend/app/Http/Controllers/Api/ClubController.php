<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
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
    use ApiResponds;

    private function toJson(Club $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'category' => $c->category,
            'description' => $c->description,
            'body' => $c->body ?? [],
        ];
    }

    public function index(): JsonResponse
    {
        return $this->ok(Club::all()->map(fn ($c) => $this->toJson($c)));
    }

    public function show(string $id): JsonResponse
    {
        $club = Club::find($id);
        if (! $club) return $this->fail(404, 'CLUB_NOT_FOUND', 'Club not found.');

        return $this->ok($this->toJson($club));
    }

    public function upsert(Request $request): JsonResponse
    {
        $id = $request->input('id');
        $name = $request->input('name');
        if (! $id || ! $name) return $this->fail(400, 'VALIDATION', 'id and name are required.');

        $isNew = ! Club::where('id', $id)->exists();
        $club = Club::updateOrCreate(['id' => $id], [
            'name' => $name,
            'category' => $request->input('category', ''),
            'description' => $request->input('description', ''),
            'body' => $request->input('body', []),
        ]);
        AuditLogger::log($request->input('actorName', 'admin'), $isNew ? 'create' : 'update', 'club', $name);

        return $this->ok($this->toJson($club));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $club = Club::find($id);
        if ($club) {
            AuditLogger::log($request->input('actorName', 'admin'), 'delete', 'club', $club->name);
            $club->delete();
        }

        return $this->ok(['deleted' => true]);
    }
}
