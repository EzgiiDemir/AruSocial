<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Api\Concerns\ModeratesContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertDirectoryEntryRequest;
use App\Models\DirectoryEntry;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DirectoryController extends Controller
{
    use ApiResponds, ModeratesContent;

    /**
     * The label for rooms filed without a floor.
     *
     * A constant because it is a *shared contract* between `floors()` and
     * `rooms()`: one synthesises it, the other has to resolve it back.
     * Written as a literal in both, they drifted — `rooms()` read it as
     * "floor IS NULL" while the upstream directory stores the same words
     * as a real value, so every room in AGE OF BRONZE and ETERNAL SPRING
     * 404'd from a floor the app itself had just listed.
     */
    private const UNSPECIFIED_FLOOR = 'Kat belirtilmemiş';

    /**
     * Rows for one building, whatever case its name is stored in.
     *
     * `buildings()` hands the app an upper-case id, so every lookup that
     * follows has to resolve it back across the mixed casing in the data.
     * Filtered in PHP for the same collation reason as the grouping there.
     *
     * @return Collection<int, DirectoryEntry>
     */
    private function entriesForBuilding(string $building): Collection
    {
        $key = mb_strtoupper($building, 'UTF-8');

        return DirectoryEntry::query()
            ->get()
            ->filter(fn (DirectoryEntry $e) => mb_strtoupper((string) $e->building, 'UTF-8') === $key)
            ->values();
    }

    /** Whether a stored floor value counts as "no floor given". */
    private function isUnspecifiedFloor(?string $floor): bool
    {
        $floor = trim((string) $floor);

        return $floor === '' || $floor === self::UNSPECIFIED_FLOOR;
    }

    private function toJson(DirectoryEntry $e): array
    {
        return [
            'id' => $e->id,
            'building' => $e->building,
            'floor' => $e->floor,
            'room' => $e->room,
            'occupantName' => $e->occupant_name,
            'occupantRole' => $e->occupant_role,
            'relatedServiceId' => $e->related_service_id,
            'tourUrl' => $e->tour_url,
            'tourTarget' => $e->tour_target,
            'campusId' => $e->campus_id,
            'campusName' => $e->campus_name,
            'buildingId' => $e->building_id,
            'categoryId' => $e->category_id,
            'categoryName' => $e->category_name,
            'roomNumber' => $e->room_number,
            'notes' => $e->notes,
            'splatSceneId' => $e->splat_scene_id,
            'splatSceneUrl' => $e->splat_scene_url,
            'location' => $e->location,
            'navigationMarker' => $e->navigation_marker,
            'directorySyncedAt' => $e->directory_synced_at?->toIso8601String(),
        ];
    }

    // Optional filters: ?building=&floor= — soft hierarchy over flat rows.
    public function index(Request $request): JsonResponse
    {
        $query = DirectoryEntry::query()->orderBy('building')->orderBy('floor')->orderBy('room');
        if ($building = $request->query('building')) {
            $query->where('building', $building);
        }
        if ($floor = $request->query('floor')) {
            $query->where('floor', $floor);
        }

        return $this->ok($query->get()->map(fn ($e) => $this->toJson($e)));
    }

    // Distinct buildings from directory_entries — no separate buildings table.
    /**
     * One row per building, matched case-insensitively.
     *
     * "TITAN" and "Titan" are the same building. They both exist because
     * the 360 sync writes upstream names in upper case while older local
     * rows kept title case, and grouping on the raw string listed each
     * of them twice — Titan, Minotaur, Meditation and Eternal Idol all
     * appeared as two buildings with the rooms split between them.
     *
     * Grouped in PHP rather than SQL because the collation differs
     * between the SQLite used in tests and the Postgres used in
     * production, and `LOWER()` on a Turkish name is not the same
     * operation in both.
     */
    public function buildings(): JsonResponse
    {
        $grouped = [];

        foreach (DirectoryEntry::query()->pluck('building') as $name) {
            $name = (string) $name;
            if (trim($name) === '') {
                continue;
            }
            $key = mb_strtoupper($name, 'UTF-8');
            if (! isset($grouped[$key])) {
                // Keep the first spelling seen as the display name, so a
                // building reads the way the directory writes it.
                $grouped[$key] = ['name' => $name, 'count' => 0];
            }
            $grouped[$key]['count']++;
        }

        ksort($grouped, SORT_NATURAL | SORT_FLAG_CASE);

        $rows = collect($grouped)->map(fn (array $row, string $key) => [
            // The id is the canonical upper-case key: it is what the app
            // sends back for floors and rooms, and it must not depend on
            // which spelling happened to be stored first.
            'id' => $key,
            'name' => $row['name'],
            'entryCount' => $row['count'],
        ])->values();

        return $this->ok($rows);
    }

    public function floors(string $building): JsonResponse
    {
        $building = urldecode($building);
        $entries = $this->entriesForBuilding($building);
        if ($entries->isEmpty()) {
            return $this->fail(404, 'BUILDING_NOT_FOUND', 'Building not found in directory.');
        }

        // Named floors first. Rows carrying the literal "Kat belirtilmemiş"
        // are counted with the unnamed ones below instead, otherwise the
        // same floor is listed twice — once from each branch.
        $named = $entries
            ->reject(fn (DirectoryEntry $e) => $this->isUnspecifiedFloor($e->floor))
            ->groupBy(fn (DirectoryEntry $e) => (string) $e->floor)
            ->map(fn ($rows, $floor) => [
                'id' => $floor,
                'name' => $floor,
                'building' => $building,
                'entryCount' => $rows->count(),
            ])
            ->values()
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $unspecified = $entries
            ->filter(fn (DirectoryEntry $e) => $this->isUnspecifiedFloor($e->floor))
            ->count();

        if ($unspecified > 0) {
            $named->push([
                'id' => self::UNSPECIFIED_FLOOR,
                'name' => self::UNSPECIFIED_FLOOR,
                'building' => $building,
                'entryCount' => $unspecified,
            ]);
        }

        return $this->ok($named);
    }

    public function rooms(string $building, string $floor): JsonResponse
    {
        $building = urldecode($building);
        $floor = urldecode($floor);
        // "Kat belirtilmemiş" is both a label synthesised for rows with no
        // floor AND a value the upstream directory actually stores.
        // Matching only NULL/'' meant every building whose rows carry the
        // literal string returned 404 from a floor the app had just been
        // told existed — AGE OF BRONZE and ETERNAL SPRING both file every
        // room that way.
        $rooms = $this->entriesForBuilding($building)
            ->filter(fn (DirectoryEntry $e) => $floor === self::UNSPECIFIED_FLOOR
                ? $this->isUnspecifiedFloor($e->floor)
                : (string) $e->floor === $floor);

        if ($rooms->isEmpty()) {
            return $this->fail(404, 'FLOOR_NOT_FOUND', 'Floor not found in that building.');
        }

        return $this->ok(
            $rooms->sortBy('room', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->map(fn (DirectoryEntry $e) => $this->toJson($e)),
        );
    }

    public function upsert(UpsertDirectoryEntryRequest $request): JsonResponse
    {
        if ($blocked = $this->moderationBlock($this->currentUser(), $this->moderationText($request->validated()), 'directory_profile', 'admin.directory.upsert')) {
            return $blocked;
        }
        $id = $request->input('id');
        $building = $request->input('building');
        $occupantName = $request->input('occupantName');

        $isNew = ! DirectoryEntry::where('id', $id)->exists();
        $entry = DirectoryEntry::updateOrCreate(['id' => $id], [
            'building' => $building,
            'floor' => $request->input('floor'),
            'room' => $request->input('room'),
            'occupant_name' => $occupantName,
            'occupant_role' => $request->input('occupantRole'),
            'related_service_id' => $request->input('relatedServiceId'),
            'tour_url' => $request->input('tourUrl'),
            'tour_target' => $request->input('tourTarget'),
        ]);
        AuditLogger::logAsCurrentUser($isNew ? 'create' : 'update', 'directory_entry', $occupantName);

        return $this->ok($this->toJson($entry));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $entry = DirectoryEntry::find($id);
        if ($entry) {
            AuditLogger::logAsCurrentUser('delete', 'directory_entry', $entry->occupant_name);
            $entry->delete();
        }

        return $this->ok(['deleted' => true]);
    }
}
