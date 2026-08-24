<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertServiceRequest;
use App\Models\ServiceItem;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceController extends Controller
{
    use ApiResponds;

    private function toJson(ServiceItem $s): array
    {
        return [
            'id' => $s->id,
            'title' => $s->title,
            'category' => $s->category,
            'description' => $s->description,
            'contact' => $s->contact,
            'building' => $s->building,
            'floor' => $s->floor,
            'room' => $s->room,
            'contactPerson' => $s->contact_person,
            'topics' => $s->topics ?? [],
            'hours' => $s->hours,
            'body' => $s->body ?? [],
        ];
    }

    public function index(): JsonResponse
    {
        return $this->ok(ServiceItem::all()->map(fn ($s) => $this->toJson($s)));
    }

    public function show(string $id): JsonResponse
    {
        $service = ServiceItem::find($id);
        if (! $service) return $this->fail(404, 'SERVICE_NOT_FOUND', 'Service not found.');

        return $this->ok($this->toJson($service));
    }

    public function upsert(UpsertServiceRequest $request): JsonResponse
    {
        $id = $request->input('id');
        $title = $request->input('title');

        $isNew = ! ServiceItem::where('id', $id)->exists();
        $service = ServiceItem::updateOrCreate(['id' => $id], [
            'title' => $title,
            'category' => $request->input('category', ''),
            'description' => $request->input('description', ''),
            'contact' => $request->input('contact', ''),
            'building' => $request->input('building'),
            'floor' => $request->input('floor'),
            'room' => $request->input('room'),
            'contact_person' => $request->input('contactPerson'),
            'topics' => $request->input('topics', []),
            'hours' => $request->input('hours'),
            'body' => $request->input('body', []),
        ]);
        AuditLogger::logAsCurrentUser($isNew ? 'create' : 'update', 'service', $title);

        return $this->ok($this->toJson($service));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $service = ServiceItem::find($id);
        if ($service) {
            AuditLogger::logAsCurrentUser('delete', 'service', $service->title);
            $service->delete();
        }

        return $this->ok(['deleted' => true]);
    }
}
