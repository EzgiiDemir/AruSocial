<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicYearController extends Controller
{
    use ApiResponds;

    private function toJson(AcademicYear $y): array
    {
        return [
            'id' => $y->id,
            'label' => $y->label,
            'startsOn' => $y->starts_on?->toDateString(),
            'endsOn' => $y->ends_on?->toDateString(),
            'isActive' => $y->is_active,
        ];
    }

    public function index(): JsonResponse
    {
        return $this->ok(AcademicYear::orderByDesc('starts_on')->get()->map(fn ($y) => $this->toJson($y)));
    }

    public function upsert(Request $request): JsonResponse
    {
        $id = $request->input('id');
        $label = $request->input('label');
        $startsOn = $request->input('startsOn');
        $endsOn = $request->input('endsOn');
        if (! $id || ! $label || ! $startsOn || ! $endsOn) {
            return $this->fail(400, 'VALIDATION', 'id, label, startsOn and endsOn are required.');
        }

        // Only one academic year is "active" at a time — activating this
        // one deactivates every other.
        $makeActive = (bool) $request->input('isActive', false);
        if ($makeActive) {
            AcademicYear::where('id', '!=', $id)->update(['is_active' => false]);
        }
        $year = AcademicYear::updateOrCreate(['id' => $id], [
            'label' => $label,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'is_active' => $makeActive,
        ]);
        AuditLogger::log($request->input('actorName', 'admin'), 'update', 'academic_year', $label);

        return $this->ok($this->toJson($year));
    }

    public function destroy(string $id): JsonResponse
    {
        AcademicYear::where('id', $id)->delete();

        return $this->ok(['deleted' => true]);
    }
}
