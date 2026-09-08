<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Api\Concerns\ModeratesContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApplyConsultationRequest;
use App\Http\Requests\PaginatedListRequest;
use App\Http\Requests\UpdateConsultationApplicationRequest;
use App\Http\Requests\UpsertConsultationRequest;
use App\Models\Consultation;
use App\Models\ConsultationApplication;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ConsultationController extends Controller
{
    use ApiResponds, ModeratesContent;

    public function index(PaginatedListRequest $request): JsonResponse
    {
        $query = Consultation::where('published', true)->orderByDesc('created_at')->orderByDesc('id');

        return $this->okPage($query, $request, fn ($c) => $c->toApiArray());
    }

    public function show(string $id): JsonResponse
    {
        $row = Consultation::where('id', $id)->where('published', true)->first();
        if (! $row) {
            return $this->fail(404, 'CONSULTATION_NOT_FOUND', 'Consultation not found.');
        }

        return $this->ok($row->toApiArray());
    }

    public function apply(ApplyConsultationRequest $request, string $id): JsonResponse
    {
        $me = $this->currentUser();
        $row = Consultation::where('id', $id)->where('published', true)->first();
        if (! $row) {
            return $this->fail(404, 'CONSULTATION_NOT_FOUND', 'Consultation not found.');
        }

        $open = ConsultationApplication::where('user_id', $me->id)
            ->where('consultation_id', $row->id)
            ->whereIn('status', ConsultationApplication::OPEN_STATUSES)
            ->exists();
        if ($open) {
            return $this->fail(409, 'APPLICATION_EXISTS', 'You already applied to this consultation.');
        }

        $app = ConsultationApplication::create([
            'id' => 'consapp-'.Str::uuid(),
            'user_id' => $me->id,
            'consultation_id' => $row->id,
            'status' => 'pending',
            'notes' => $request->input('notes'),
        ]);
        $app->load(['user', 'consultation']);

        return $this->ok($app->toApiArray(), 201);
    }

    public function myApplications(): JsonResponse
    {
        $me = $this->currentUser();
        $rows = ConsultationApplication::with(['consultation', 'user'])
            ->where('user_id', $me->id)
            ->orderByDesc('created_at')
            ->get();

        return $this->ok($rows->map->toApiArray());
    }

    public function adminIndex(PaginatedListRequest $request): JsonResponse
    {
        $query = Consultation::orderByDesc('created_at')->orderByDesc('id');

        return $this->okPage($query, $request, fn ($c) => $c->toApiArray());
    }

    public function upsert(UpsertConsultationRequest $request): JsonResponse
    {
        $id = $request->input('id') ?: $this->newId('consult');
        $existing = Consultation::find($id);
        $isNew = $existing === null;

        $row = Consultation::updateOrCreate(['id' => $id], [
            'title' => $request->input('title'),
            'purpose' => $request->input('purpose'),
            'audience' => $request->input('audience'),
            'content' => $request->input('content'),
            'outcomes' => $request->input('outcomes'),
            'duration' => $request->input('duration'),
            'format' => $request->input('format'),
            'requirements' => $request->input('requirements'),
            'counselor_name' => $request->input('counselorName'),
            'counselor_staff_id' => $request->input('counselorStaffId'),
            'published' => $request->input('published', true),
        ]);

        AuditLogger::logAsCurrentUser($isNew ? 'create' : 'update', 'consultation', $row->title);

        return $this->ok($row->toApiArray(), $isNew ? 201 : 200);
    }

    public function destroy(string $id): JsonResponse
    {
        $row = Consultation::find($id);
        if ($row) {
            AuditLogger::logAsCurrentUser('delete', 'consultation', $row->title);
            $row->delete();
        }

        return $this->ok(['deleted' => true]);
    }

    public function adminApplications(Request $request): JsonResponse
    {
        $query = ConsultationApplication::with(['user', 'consultation'])->orderByDesc('created_at');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($cid = $request->query('consultationId')) {
            $query->where('consultation_id', $cid);
        }
        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%"))
                    ->orWhereHas('consultation', fn ($c) => $c->where('title', 'like', "%{$q}%"));
            });
        }

        return $this->ok($query->limit(200)->get()->map(fn ($a) => $a->toApiArray(true)));
    }

    public function updateApplication(UpdateConsultationApplicationRequest $request, string $id): JsonResponse
    {
        $app = ConsultationApplication::with(['user', 'consultation'])->find($id);
        if (! $app) {
            return $this->fail(404, 'APPLICATION_NOT_FOUND', 'Application not found.');
        }
        if ($request->exists('status')) {
            $app->status = $request->input('status');
        }
        if ($request->exists('adminNotes')) {
            $app->admin_notes = $request->input('adminNotes');
        }
        $app->save();
        AuditLogger::logAsCurrentUser('update', 'consultation_application', $app->id);

        return $this->ok($app->fresh(['user', 'consultation'])->toApiArray(true));
    }
}
