<?php

namespace App\Http\Controllers\Api\Trainer;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\StaffProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// Read-only view of this trainer's own department colleagues — the
// department is resolved server-side (`department-head` middleware),
// never accepted from the client, so a trainer can only ever see their
// own department's roster.
class RosterController extends Controller
{
    use ApiResponds;

    public function index(Request $request): JsonResponse
    {
        $staff = $request->attributes->get('departmentHeadStaff');
        $peers = StaffProfile::where('department', $staff->department)
            ->where('active', true)
            ->orderByDesc('is_department_head')
            ->orderBy('name')
            ->get();

        return $this->ok($peers->map->toApiArray());
    }
}
