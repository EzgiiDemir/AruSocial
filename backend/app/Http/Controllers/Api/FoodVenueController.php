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
        AuditLogger::log($this->currentUser()->name, $isNew ? 'create' : 'update', 'food_venue', $name);

        return $this->ok($this->toJson($venue->fresh('dailyMenus')));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $venue = FoodVenue::find($id);
        if ($venue) {
            AuditLogger::log($this->currentUser()->name, 'delete', 'food_venue', $venue->name);
            $venue->delete();
        }

        return $this->ok(['deleted' => true]);
    }

    // Real bug fix: `menu_date` is cast to `date`, which Eloquent stores
    // with a time component under sqlite (e.g. "2026-08-21 00:00:00"), but
    // a plain `where('menu_date', $date)` match against a raw "YYYY-MM-DD"
    // string never matched that stored value — updateOrCreate's match
    // clause silently failed to find the existing row and tried to INSERT
    // a duplicate instead, crashing on the (food_venue_id, menu_date)
    // unique constraint the very first time an admin re-edited an
    // already-set day. whereDate() compares just the date portion,
    // portable across sqlite/mysql, so this now genuinely finds and
    // updates the existing row.
    public function upsertMenu(Request $request, string $venueId): JsonResponse
    {
        $venue = FoodVenue::find($venueId);
        if (! $venue) return $this->fail(404, 'FOOD_VENUE_NOT_FOUND', 'Food venue not found.');
        $date = $request->input('date');
        if (! $date) return $this->fail(400, 'VALIDATION', 'date is required.');

        $attributes = [
            'items' => $request->input('items', []),
            'price' => $request->input('price'),
            'hours' => $request->input('hours'),
        ];
        $existing = FoodDailyMenu::where('food_venue_id', $venueId)->whereDate('menu_date', $date)->first();
        if ($existing) {
            $existing->update($attributes);
        } else {
            FoodDailyMenu::create([
                'id' => 'menu-'.Str::uuid(),
                'food_venue_id' => $venueId,
                'menu_date' => $date,
                ...$attributes,
            ]);
        }
        AuditLogger::log($this->currentUser()->name, 'update', 'food_menu', "{$venue->name} · $date");

        return $this->ok($this->toJson($venue->fresh('dailyMenus')));
    }

    public function destroyMenu(Request $request, string $venueId, string $date): JsonResponse
    {
        FoodDailyMenu::where('food_venue_id', $venueId)->whereDate('menu_date', $date)->delete();

        return $this->ok(['deleted' => true]);
    }
}
