<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertDirectoryEntryRequest;
use App\Models\DirectoryEntry;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DirectoryController extends Controller
{
    use ApiResponds;

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
    public function buildings(): JsonResponse
    {
        $rows = DirectoryEntry::query()
            ->selectRaw('building, count(*) as entry_count')
            ->groupBy('building')
            ->orderBy('building')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->building,
                'name' => $r->building,
                'entryCount' => (int) $r->entry_count,
            ]);

        return $this->ok($rows);
    }

    public function floors(string $building): JsonResponse
    {
        $building = urldecode($building);
        $exists = DirectoryEntry::where('building', $building)->exists();
        if (! $exists) {
            return $this->fail(404, 'BUILDING_NOT_FOUND', 'Building not found in directory.');
        }

        $floors = DirectoryEntry::where('building', $building)
            ->whereNotNull('floor')
            ->where('floor', '!=', '')
            ->selectRaw('floor, count(*) as entry_count')
            ->groupBy('floor')
            ->orderBy('floor')
            ->get()
            ->map(fn ($r) => [
                'id' => $r->floor,
                'name' => $r->floor,
                'building' => $building,
                'entryCount' => (int) $r->entry_count,
            ]);

        if (DirectoryEntry::where('building', $building)
            ->where(fn ($q) => $q->whereNull('floor')->orWhere('floor', ''))
            ->exists()) {
            $floors->push([
                'id' => 'Kat belirtilmemiş',
                'name' => 'Kat belirtilmemiş',
                'building' => $building,
                'entryCount' => DirectoryEntry::where('building', $building)
                    ->where(fn ($q) => $q->whereNull('floor')->orWhere('floor', ''))->count(),
            ]);
        }

        return $this->ok($floors);
    }

    public function rooms(string $building, string $floor): JsonResponse
    {
        $building = urldecode($building);
        $floor = urldecode($floor);
        $query = DirectoryEntry::where('building', $building);
        if ($floor === 'Kat belirtilmemiş') {
            $query->where(fn ($q) => $q->whereNull('floor')->orWhere('floor', ''));
        } else {
            $query->where('floor', $floor);
        }
        $exists = (clone $query)->exists();
        if (! $exists) {
            return $this->fail(404, 'FLOOR_NOT_FOUND', 'Floor not found in that building.');
        }

        $rooms = $query
            ->orderBy('room')
            ->get()
            ->map(fn ($e) => [
                'id' => $e->id,
                'room' => $e->room,
                'building' => $e->building,
                'floor' => $e->floor,
                'occupantName' => $e->occupant_name,
                'occupantRole' => $e->occupant_role,
                'relatedServiceId' => $e->related_service_id,
                'tourUrl' => $e->tour_url,
                'tourTarget' => $e->tour_target,
            ]);

        return $this->ok($rooms);
    }

    public function upsert(UpsertDirectoryEntryRequest $request): JsonResponse
    {
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
