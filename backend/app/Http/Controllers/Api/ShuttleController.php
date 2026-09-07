<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertShuttleRouteRequest;
use App\Models\ShuttleRoute;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;

// Real replacement for the previously hardcoded `shuttleRoutes` const in
// `shuttle_config.dart` — the only campus schedule with no backend table or
// admin screen at all. Mock mode keeps that const as its offline seed only.
class ShuttleController extends Controller
{
    use ApiResponds;

    private function toJson(ShuttleRoute $r): array
    {
        return [
            'id' => $r->id,
            'name' => $r->name,
            'colorKey' => $r->color_key,
            'stops' => $r->stops ?? [],
            'departures' => $r->departures ?? [],
            'returns' => $r->returns,
        ];
    }

    public function index(): JsonResponse
    {
        $routes = ShuttleRoute::orderBy('sort_order')->orderBy('name')->get();

        return $this->ok($routes->map(fn ($r) => $this->toJson($r)));
    }

    public function upsert(UpsertShuttleRouteRequest $request): JsonResponse
    {
        $id = $request->input('id');
        $isNew = ! ShuttleRoute::where('id', $id)->exists();
        $route = ShuttleRoute::updateOrCreate(['id' => $id], [
            'name' => $request->input('name'),
            'color_key' => $request->input('colorKey', 'blue'),
            'stops' => $request->input('stops'),
            'departures' => $request->input('departures'),
            'returns' => $request->input('returns'),
            'sort_order' => (int) $request->input('sortOrder', 0),
        ]);
        AuditLogger::logAsCurrentUser($isNew ? 'create' : 'update', 'shuttle_route', $route->name);

        return $this->ok($this->toJson($route), $isNew ? 201 : 200);
    }

    public function destroy(string $id): JsonResponse
    {
        $route = ShuttleRoute::find($id);
        if ($route) {
            AuditLogger::logAsCurrentUser('delete', 'shuttle_route', $route->name);
            $route->delete();
        }

        return $this->ok(['deleted' => true]);
    }
}
