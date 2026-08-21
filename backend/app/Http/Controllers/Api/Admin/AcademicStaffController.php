<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\AcademicStaff;
use Illuminate\Http\JsonResponse;

// Real ARUCAD personnel directory (docs/EKSIKLER.md aktivite/onay
// workflow §8/§10) — backs the admin "E-posta" recipient picker. Staff
// with no confirmed email (email_verified=false) are still listed (an
// admin may know a better address to use), but flagged so the UI can be
// honest instead of hiding the gap.
class AcademicStaffController extends Controller
{
    use ApiResponds;

    public function index(): JsonResponse
    {
        $staff = AcademicStaff::orderBy('name')->get()->map(fn (AcademicStaff $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'email' => $s->email,
            'emailVerified' => $s->email_verified,
            'type' => $s->type,
            'faculty' => $s->faculty,
            'department' => $s->department,
            'title' => $s->title,
            'isDepartmentHead' => $s->is_department_head,
            'isFacultyDean' => $s->is_faculty_dean,
        ]);

        return $this->ok($staff);
    }
}
