<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\FoodDailyMenu;
use App\Models\FoodVenue;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FoodVenueController extends Controller
{
    use ApiResponds;

    private function toJson(FoodVenue $v): array
    {
        return [
            'id' => $v->id,
            'name' => $v->name,
            'hours' => $v->hours,
            'menuFileUrl' => $v->menu_file_url,
            'dailyMenus' => $v->dailyMenus->map(fn ($m) => [
                'date' => $m->menu_date->toIso8601String(),
                'items' => $m->items ?? [],
                'price' => $m->price,
                'hours' => $m->hours,
            ]),
        ];
    }

    public function index(): JsonResponse
    {
        return $this->ok(FoodVenue::with('dailyMenus')->get()->map(fn ($v) => $this->toJson($v)));
    }

    public function upsert(Request $request): JsonResponse
    {
        $id = $request->input('id');
        $name = $request->input('name');
        if (! $id || ! $name) return $this->fail(400, 'VALIDATION', 'id and name are required.');

        $isNew = ! FoodVenue::where('id', $id)->exists();
        $venue = FoodVenue::updateOrCreate(['id' => $id], [
            'name' => $name,
            'hours' => $request->input('hours'),
            'menu_file_url' => $request->input('menuFileUrl'),
        ]);
        AuditLogger::log($request->input('actorName', 'admin'), $isNew ? 'create' : 'update', 'food_venue', $name);

        return $this->ok($this->toJson($venue->fresh('dailyMenus')));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $venue = FoodVenue::find($id);
        if ($venue) {
            AuditLogger::log($request->input('actorName', 'admin'), 'delete', 'food_venue', $venue->name);
            $venue->delete();
        }

        return $this->ok(['deleted' => true]);
    }

    public function upsertMenu(Request $request, string $venueId): JsonResponse
    {
        $venue = FoodVenue::find($venueId);
        if (! $venue) return $this->fail(404, 'FOOD_VENUE_NOT_FOUND', 'Food venue not found.');
        $date = $request->input('date');
        if (! $date) return $this->fail(400, 'VALIDATION', 'date is required.');

        FoodDailyMenu::updateOrCreate(
            ['food_venue_id' => $venueId, 'menu_date' => $date],
            [
                'id' => 'menu-'.Str::uuid(),
                'items' => $request->input('items', []),
                'price' => $request->input('price'),
                'hours' => $request->input('hours'),
            ]
        );
        AuditLogger::log($request->input('actorName', 'admin'), 'update', 'food_menu', "{$venue->name} · $date");

        return $this->ok($this->toJson($venue->fresh('dailyMenus')));
    }

    public function destroyMenu(Request $request, string $venueId, string $date): JsonResponse
    {
        FoodDailyMenu::where('food_venue_id', $venueId)->where('menu_date', $date)->delete();

        return $this->ok(['deleted' => true]);
    }
}
