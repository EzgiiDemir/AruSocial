<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Api\Concerns\ModeratesContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertStaffProfileRequest;
use App\Models\StaffProfile;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StaffController extends Controller
{
    use ApiResponds, ModeratesContent;

    public function index(Request $request): JsonResponse
    {
        $query = StaffProfile::query()->where('active', true);

        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', "%{$q}%")
                    ->orWhere('department', 'like', "%{$q}%")
                    ->orWhere('title', 'like', "%{$q}%")
                    ->orWhere('faculty', 'like', "%{$q}%");
            });
        }
        if ($faculty = $request->query('faculty')) {
            $query->where('faculty', $faculty);
        }
        if ($department = $request->query('department')) {
            $query->where('department', $department);
        }
        if ($title = $request->query('title')) {
            $query->where('title', 'like', "%{$title}%");
        }
        if ($request->boolean('departmentHeadOnly')) {
            $query->where('is_department_head', true);
        }

        return $this->ok($query->orderBy('name')->get()->map->toApiArray());
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $query = StaffProfile::query();
        if ($request->has('active')) {
            $query->where('active', $request->boolean('active'));
        }
        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%")
                    ->orWhere('department', 'like', "%{$q}%");
            });
        }
        if ($department = $request->query('department')) {
            $query->where('department', $department);
        }
        if ($request->boolean('departmentHeadOnly')) {
            $query->where('is_department_head', true);
        }

        return $this->ok($query->orderBy('name')->get()->map->toApiArray());
    }

    public function upsert(UpsertStaffProfileRequest $request): JsonResponse
    {
        if ($blocked = $this->moderationBlock($this->currentUser(), $this->moderationText($request->validated()), 'directory_profile', 'admin.staff.upsert')) {
            return $blocked;
        }
        $id = $request->input('id') ?: 'staff-'.Str::uuid();
        $isNew = ! StaffProfile::where('id', $id)->exists();

        // Never invent emails — empty string becomes null.
        $email = $request->input('email');
        $email = is_string($email) && trim($email) !== '' ? strtolower(trim($email)) : null;
        // Operators link an existing account by its email, without knowing SQL IDs.
        $userId = $request->input('userId');
        if ($userId === null && $email !== null) {
            $userId = User::whereRaw('LOWER(email) = ?', [$email])->value('id');
        }

        $staff = StaffProfile::updateOrCreate(['id' => $id], [
            'name' => $request->input('name'),
            'faculty' => $request->input('faculty'),
            'department' => $request->input('department'),
            'title' => $request->input('title'),
            'email' => $email,
            'is_department_head' => $request->boolean('isDepartmentHead'),
            'active' => $request->boolean('active', true),
            'user_id' => $userId,
        ]);
        AuditLogger::logAsCurrentUser($isNew ? 'create' : 'update', 'staff', $staff->name);

        return $this->ok($staff->toApiArray(), $isNew ? 201 : 200);
    }

    public function destroy(string $id): JsonResponse
    {
        $staff = StaffProfile::find($id);
        if ($staff) {
            AuditLogger::logAsCurrentUser('delete', 'staff', $staff->name);
            $staff->delete();
        }

        return $this->ok(['deleted' => true]);
    }
}
