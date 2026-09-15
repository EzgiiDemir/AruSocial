<?php

namespace App\Http\Middleware;

use App\Models\AcademicYear;
use App\Models\AchievementDefinition;
use App\Models\AdminPage;
use App\Models\Appointment;
use App\Models\CareerOpportunity;
use App\Models\Club;
use App\Models\DirectoryEntry;
use App\Models\Event;
use App\Models\FoodVenue;
use App\Models\MediaItem;
use App\Models\OnboardingStep;
use App\Models\ParticipationApplication;
use App\Models\Place;
use App\Models\ServiceItem;
use App\Models\ShuttleRoute;
use App\Models\Sport;
use App\Models\StaffProfile;
use App\Models\Survey;
use App\Services\GranularPermissions;
use App\Services\ScopedAccess;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

// Route-level authorization. Until now every /admin/* route trusted any
// request that reached it, so "hidden in the Flutter UI" was the only thing
// standing between a student's token and the admin API — which a plain curl
// walks straight past.
//
// Authentication and authorization answer different questions and get
// different answers here: no token at all is a 401 (who are you?), a valid
// token without the permission is a 403 (I know who you are, and no).
// Collapsing them would tell an attacker that a wrong token might work.
class EnsurePermission
{
    private const MODELS = [
        'events.manage' => Event::class,
        'pendingActivities.manage' => Event::class,
        'clubs.manage' => Club::class,
        'places.manage' => Place::class,
        'sports.manage' => Sport::class,
        'services.manage' => ServiceItem::class,
        'food.manage' => FoodVenue::class,
        'directory.manage' => DirectoryEntry::class,
        'pages.manage' => AdminPage::class,
        'media.manage' => MediaItem::class,
        'career.manage' => CareerOpportunity::class,
        'surveys.manage' => Survey::class,
        'academicYears.manage' => AcademicYear::class,
        'staff.manage' => StaffProfile::class,
        'applications.manage' => ParticipationApplication::class,
        'appointments.manage' => Appointment::class,
        'achievements.manage' => AchievementDefinition::class,
        'shuttle.manage' => ShuttleRoute::class,
        'onboarding.manage' => OnboardingStep::class,
    ];

    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        // Normally unreachable: every route using this also runs
        // `auth:sanctum` first. Kept so that adding this middleware to a
        // route someone forgot to authenticate fails closed, as a 401,
        // instead of dereferencing null.
        if ($user === null) {
            throw new AuthenticationException;
        }

        if (! GranularPermissions::allows($user, $permission)) {
            return response()->json([
                'data' => null,
                'meta' => ['request_id' => 'req-'.Str::uuid()],
                'error' => [
                    'code' => 'FORBIDDEN',
                    'message' => 'Bu işlem için yetkin yok.',
                ],
            ], 403);
        }

        // Menu visibility and action permission are only the first two
        // gates. Existing records must also sit inside one of this user's
        // active scopes. New/create requests have no record yet and their
        // controller stamps ownership; record requests are checked here so
        // a direct API call cannot bypass the panel's scoped query.
        $modelClass = self::MODELS[$permission] ?? null;
        if ($modelClass !== null) {
            foreach (['id', 'eventId', 'applicationId', 'opportunityId', 'record'] as $parameter) {
                $value = $request->route($parameter);
                if ($value === null) {
                    continue;
                }
                $record = $value instanceof Model ? $value : null;
                if ($record === null) {
                    $query = $modelClass::query();
                    if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
                        $query->withTrashed();
                    }
                    $record = $query->find($value);
                }
                if ($record !== null && ! ScopedAccess::recordAllowed($record, $user, $permission)) {
                    return response()->json([
                        'data' => null,
                        'meta' => ['request_id' => 'req-'.Str::uuid()],
                        'error' => ['code' => 'OUT_OF_SCOPE', 'message' => 'Bu kayıt yetki kapsamının dışında.'],
                    ], 403);
                }
                break;
            }
        }

        return $next($request);
    }
}
