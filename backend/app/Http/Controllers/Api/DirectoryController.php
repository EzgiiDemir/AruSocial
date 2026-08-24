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
        ];
    }

    public function index(): JsonResponse
    {
        return $this->ok(DirectoryEntry::all()->map(fn ($e) => $this->toJson($e)));
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
