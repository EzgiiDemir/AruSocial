<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Api\Concerns\ModeratesContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginatedListRequest;
use App\Http\Requests\StoreCareerCvRequest;
use App\Http\Requests\UpdateCareerApplicationRequest;
use App\Http\Requests\UpdateCareerProfileRequest;
use App\Http\Requests\UpsertCareerOpportunityRequest;
use App\Models\CareerApplication;
use App\Models\CareerOpportunity;
use App\Models\CareerProfile;
use App\Services\AuditLogger;
use App\Services\CareerCvStorage;
use App\Services\GranularPermissions;
use App\Support\UploadMagic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CareerController extends Controller
{
    use ApiResponds, ModeratesContent;

    private function opportunityToJson(CareerOpportunity $o): array
    {
        return [
            'id' => $o->id,
            'title' => $o->title,
            'kind' => $o->kind,
            'organization' => $o->organization,
            'department' => $o->department,
            'url' => $o->url,
            'deadline' => $o->deadline?->toDateString(),
            'postedAt' => $o->posted_at?->toDateString(),
            'description' => $o->description,
            'purpose' => $o->purpose,
            'skills' => $o->skills,
            'experience' => $o->experience,
            'education' => $o->education,
            'workType' => $o->work_type,
            'location' => $o->location,
            'extraInfo' => $o->extra_info,
            'published' => (bool) $o->published,
            'createdAt' => $o->created_at?->toIso8601String(),
        ];
    }

    private function profileToJson(?CareerProfile $p): array
    {
        return [
            'occupation' => $p?->headline,
            'headline' => $p?->headline,
            'expertise' => $p?->expertise,
            'hasCv' => CareerCvStorage::exists($p?->cv_path),
            'cvFileName' => $p?->cv_original_name,
            'cvMime' => $p?->cv_mime,
            'cvSizeBytes' => $p?->cv_size_bytes,
            'cvUrl' => null,
            'lookingForInternships' => (bool) ($p?->looking_for_internships ?? false),
            'lookingForJobs' => (bool) ($p?->looking_for_jobs ?? false),
            'updatedAt' => $p?->updated_at?->toIso8601String(),
        ];
    }

    public function opportunities(PaginatedListRequest $request): JsonResponse
    {
        $query = CareerOpportunity::where('published', true)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        return $this->okPage($query, $request, fn ($o) => $this->opportunityToJson($o));
    }

    public function showOpportunity(string $id): JsonResponse
    {
        $opp = CareerOpportunity::where('id', $id)->where('published', true)->first();
        if (! $opp) {
            return $this->fail(404, 'OPPORTUNITY_NOT_FOUND', 'Opportunity not found.');
        }

        return $this->ok($this->opportunityToJson($opp));
    }

    public function adminOpportunities(PaginatedListRequest $request): JsonResponse
    {
        $query = CareerOpportunity::orderByDesc('created_at')->orderByDesc('id');

        return $this->okPage($query, $request, fn ($o) => $this->opportunityToJson($o));
    }

    public function profile(): JsonResponse
    {
        $me = $this->currentUser();
        $profile = CareerProfile::where('user_id', $me->id)->first();

        return $this->ok($this->profileToJson($profile));
    }

    public function updateProfile(UpdateCareerProfileRequest $request): JsonResponse
    {
        $me = $this->currentUser();
        $profile = CareerProfile::firstOrNew(['user_id' => $me->id]);

        $occupation = $request->input('occupation', $request->input('headline'));

        // Both fields are shown to other students on the career board, so
        // they are public profile text and moderated as such — the same
        // rule the social bio already follows.
        $freeText = trim(
            (string) $occupation."\n".(string) $request->input('expertise', ''),
        );
        if ($blocked = $this->moderationBlock(
            $me, $freeText, 'profile', 'career.updateProfile',
        )) {
            return $blocked;
        }

        if ($request->exists('occupation') || $request->exists('headline')) {
            $profile->headline = $occupation;
        }
        if ($request->exists('expertise')) {
            $profile->expertise = $request->input('expertise');
        }
        if ($request->exists('lookingForInternships')) {
            $profile->looking_for_internships = $request->boolean('lookingForInternships');
        }
        if ($request->exists('lookingForJobs')) {
            $profile->looking_for_jobs = $request->boolean('lookingForJobs');
        }
        $profile->updated_at = now();
        $profile->save();

        return $this->ok($this->profileToJson($profile));
    }

    public function uploadCv(StoreCareerCvRequest $request): JsonResponse
    {
        $me = $this->currentUser();
        $file = $request->file('file');
        $mime = $file->getMimeType();
        if ($mime && ! in_array($mime, CareerCvStorage::MIMES, true)) {
            return $this->fail(400, 'UNSUPPORTED_FILE_TYPE', 'Only PDF and Word CVs are accepted.');
        }
        if ($file->getSize() > CareerCvStorage::MAX_BYTES) {
            return $this->fail(400, 'FILE_TOO_LARGE', 'CV exceeds the 8MB limit.');
        }
        if (! UploadMagic::isDocument($file)) {
            return $this->fail(400, 'INVALID_FILE_CONTENTS', 'File contents do not match a PDF or Word document.');
        }

        $profile = CareerProfile::firstOrNew(['user_id' => $me->id]);
        CareerCvStorage::delete($profile->cv_path);
        $stored = CareerCvStorage::store($me, $file);
        $profile->cv_path = $stored['path'];
        $profile->cv_original_name = $stored['original'];
        $profile->cv_mime = $stored['mime'];
        $profile->cv_size_bytes = $stored['size'];
        $profile->cv_url = null;
        $profile->updated_at = now();
        $profile->save();

        return $this->ok($this->profileToJson($profile));
    }

    public function downloadOwnCv()
    {
        $me = $this->currentUser();
        $profile = CareerProfile::where('user_id', $me->id)->first();
        if (! $profile || ! CareerCvStorage::exists($profile->cv_path)) {
            return $this->fail(404, 'CV_NOT_FOUND', 'No CV uploaded.');
        }

        return CareerCvStorage::download($profile);
    }

    public function deleteCv(): JsonResponse
    {
        $me = $this->currentUser();
        $profile = CareerProfile::where('user_id', $me->id)->first();
        if ($profile) {
            CareerCvStorage::delete($profile->cv_path);
            $profile->cv_path = null;
            $profile->cv_original_name = null;
            $profile->cv_mime = null;
            $profile->cv_size_bytes = null;
            $profile->cv_url = null;
            $profile->updated_at = now();
            $profile->save();
        }

        return $this->ok($this->profileToJson($profile));
    }

    public function apply(string $id): JsonResponse
    {
        $me = $this->currentUser();
        $opp = CareerOpportunity::where('id', $id)->where('published', true)->first();
        if (! $opp) {
            return $this->fail(404, 'OPPORTUNITY_NOT_FOUND', 'Opportunity not found.');
        }

        $open = CareerApplication::where('user_id', $me->id)
            ->where('opportunity_id', $opp->id)
            ->whereIn('status', CareerApplication::OPEN_STATUSES)
            ->exists();
        if ($open) {
            return $this->fail(409, 'APPLICATION_EXISTS', 'You already applied to this opportunity.');
        }

        $profile = CareerProfile::where('user_id', $me->id)->first();
        if (! $profile || ! CareerCvStorage::exists($profile->cv_path)) {
            return $this->fail(400, 'CV_REQUIRED', 'Upload a CV before applying.');
        }

        $app = CareerApplication::create([
            'id' => 'capp-'.Str::uuid(),
            'user_id' => $me->id,
            'opportunity_id' => $opp->id,
            'cv_path' => $profile->cv_path,
            'cv_original_name' => $profile->cv_original_name,
            'cv_mime' => $profile->cv_mime,
            'status' => 'pending',
        ]);
        $app->load(['user', 'opportunity']);

        return $this->ok($app->toApiArray(), 201);
    }

    public function myApplications(): JsonResponse
    {
        $me = $this->currentUser();
        $rows = CareerApplication::with(['opportunity', 'user'])
            ->where('user_id', $me->id)
            ->orderByDesc('created_at')
            ->get();

        return $this->ok($rows->map->toApiArray());
    }

    public function upsertOpportunity(UpsertCareerOpportunityRequest $request): JsonResponse
    {
        $id = $request->input('id') ?: $this->newId('career');
        $existing = CareerOpportunity::find($id);
        $isNew = $existing === null;

        $opp = CareerOpportunity::updateOrCreate(['id' => $id], [
            'title' => $request->input('title'),
            'kind' => $request->input('kind'),
            'organization' => $request->input('organization', ''),
            'department' => $request->input('department'),
            'url' => $request->input('url'),
            'deadline' => $request->input('deadline'),
            'posted_at' => $request->input('postedAt'),
            'description' => $request->input('description'),
            'purpose' => $request->input('purpose'),
            'skills' => $request->input('skills'),
            'experience' => $request->input('experience'),
            'education' => $request->input('education'),
            'work_type' => $request->input('workType'),
            'location' => $request->input('location'),
            'extra_info' => $request->input('extraInfo'),
            'published' => $request->input('published', true),
            'created_at' => $existing?->created_at ?? now(),
        ]);

        AuditLogger::logAsCurrentUser($isNew ? 'create' : 'update', 'career_opportunity', $opp->title);

        return $this->ok($this->opportunityToJson($opp), $isNew ? 201 : 200);
    }

    public function destroyOpportunity(Request $request, string $id): JsonResponse
    {
        $opp = CareerOpportunity::find($id);
        if ($opp) {
            AuditLogger::logAsCurrentUser('delete', 'career_opportunity', $opp->title);
            $opp->delete();
        }

        return $this->ok(['deleted' => true]);
    }

    public function adminApplications(Request $request): JsonResponse
    {
        $query = CareerApplication::with(['user', 'opportunity'])->orderByDesc('created_at');
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        if ($oppId = $request->query('opportunityId')) {
            $query->where('opportunity_id', $oppId);
        }
        if ($q = trim((string) $request->query('q', ''))) {
            $query->where(function ($inner) use ($q) {
                $inner->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%"))
                    ->orWhereHas('opportunity', fn ($o) => $o->where('title', 'like', "%{$q}%"));
            });
        }

        return $this->ok($query->limit(200)->get()->map(fn ($a) => $a->toApiArray(true)));
    }

    public function updateApplication(UpdateCareerApplicationRequest $request, string $id): JsonResponse
    {
        $app = CareerApplication::with(['user', 'opportunity'])->find($id);
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
        AuditLogger::logAsCurrentUser('update', 'career_application', $app->id);

        return $this->ok($app->fresh(['user', 'opportunity'])->toApiArray(true));
    }

    public function downloadApplicationCv(string $id): StreamedResponse|JsonResponse
    {
        $me = $this->currentUser();
        $app = CareerApplication::find($id);
        if (! $app || ! CareerCvStorage::exists($app->cv_path)) {
            return $this->fail(404, 'CV_NOT_FOUND', 'CV not found.');
        }
        $owns = (int) $app->user_id === (int) $me->id;
        if (! $owns && ! GranularPermissions::allows($me, 'career.manage')) {
            return $this->fail(404, 'CV_NOT_FOUND', 'CV not found.');
        }

        return CareerCvStorage::downloadPath($app->cv_path, $app->cv_original_name, $app->cv_mime);
    }
}
