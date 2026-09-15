<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Api\Concerns\ModeratesContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertSportRequest;
use App\Models\Sport;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SportController extends Controller
{
    use ApiResponds, ModeratesContent;

    private function toJson(Sport $s): array
    {
        return ['id' => $s->id, 'name' => $s->name, 'facility' => $s->facility, 'contact' => $s->contact];
    }

    public function index(): JsonResponse
    {
        return $this->ok(Sport::all()->map(fn ($s) => $this->toJson($s)));
    }

    public function upsert(UpsertSportRequest $request): JsonResponse
    {
        if ($blocked = $this->moderationBlock($this->currentUser(), $this->moderationText($request->validated()), 'catalog', 'admin.sport.upsert')) {
            return $blocked;
        }
        $id = $request->input('id');
        $name = $request->input('name');

        $isNew = ! Sport::where('id', $id)->exists();
        $sport = Sport::updateOrCreate(['id' => $id], [
            'name' => $name,
            'facility' => $request->input('facility', ''),
            'contact' => $request->input('contact'),
        ]);
        AuditLogger::logAsCurrentUser($isNew ? 'create' : 'update', 'sport', $name);

        return $this->ok($this->toJson($sport));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $sport = Sport::find($id);
        if ($sport) {
            AuditLogger::logAsCurrentUser('delete', 'sport', $sport->name);
            $sport->delete();
        }

        return $this->ok(['deleted' => true]);
    }
}
