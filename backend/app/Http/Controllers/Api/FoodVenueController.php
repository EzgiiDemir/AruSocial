<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertFoodMenuRequest;
use App\Http\Requests\UpsertFoodVenueRequest;
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
                'date' => $m->menu_date?->toDateString(),
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

    public function upsert(UpsertFoodVenueRequest $request): JsonResponse
    {
        $id = $request->input('id');
        $name = $request->input('name');

        $isNew = ! FoodVenue::where('id', $id)->exists();
        $venue = FoodVenue::updateOrCreate(['id' => $id], [
            'name' => $name,
            'hours' => $request->input('hours'),
            'menu_file_url' => $request->input('menuFileUrl'),
        ]);
        AuditLogger::logAsCurrentUser($isNew ? 'create' : 'update', 'food_venue', $name);

        return $this->ok($this->toJson($venue->fresh('dailyMenus')));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $venue = FoodVenue::find($id);
        if ($venue) {
            AuditLogger::logAsCurrentUser('delete', 'food_venue', $venue->name);
            $venue->delete();
        }

        return $this->ok(['deleted' => true]);
    }

    public function upsertMenu(UpsertFoodMenuRequest $request, string $venueId): JsonResponse
    {
        $venue = FoodVenue::find($venueId);
        if (! $venue) return $this->fail(404, 'FOOD_VENUE_NOT_FOUND', 'Food venue not found.');
        $date = $request->input('date');

        // menu_date is a date cast; firstOrNew on the raw string misses the
        // stored midnight datetime on SQLite and then INSERT hits the unique
        // (food_venue_id, menu_date) key. Match by calendar day instead.
        $menu = FoodDailyMenu::where('food_venue_id', $venueId)
            ->whereDate('menu_date', $date)
            ->first();
        if (! $menu) {
            $menu = new FoodDailyMenu([
                'id' => 'menu-'.Str::uuid(),
                'food_venue_id' => $venueId,
                'menu_date' => $date,
            ]);
        }
        $menu->items = $request->input('items', []);
        $menu->price = $request->input('price');
        $menu->hours = $request->input('hours');
        $menu->save();
        AuditLogger::logAsCurrentUser('update', 'food_menu', "{$venue->name} · $date");

        return $this->ok($this->toJson($venue->fresh('dailyMenus')));
    }

    public function destroyMenu(Request $request, string $venueId, string $date): JsonResponse
    {
        $venue = FoodVenue::find($venueId);
        $deleted = FoodDailyMenu::where('food_venue_id', $venueId)->whereDate('menu_date', $date)->delete();
        if ($deleted) {
            AuditLogger::logAsCurrentUser('delete', 'food_menu', ($venue?->name ?? $venueId).' · '.$date);
        }

        return $this->ok(['deleted' => true]);
    }
}
