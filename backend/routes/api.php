<?php

use App\Http\Controllers\Api\Admin\EmailController as AdminEmailController;
use App\Http\Controllers\Api\Admin\EventController as AdminEventController;
use App\Http\Controllers\Api\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Api\Admin\StatsController as AdminStatsController;
use App\Http\Controllers\Api\AcademicYearController;
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\CheckinController;
use App\Http\Controllers\Api\ClubController;
use App\Http\Controllers\Api\ContentRevisionController;
use App\Http\Controllers\Api\DirectoryController;
use App\Http\Controllers\Api\EventController;
use App\Http\Controllers\Api\FeedController;
use App\Http\Controllers\Api\FoodVenueController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\MediaController;
use App\Http\Controllers\Api\ModerationController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\PageController;
use App\Http\Controllers\Api\PlaceController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SavedPostController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\SocialGraphController;
use App\Http\Controllers\Api\SportController;
use App\Http\Controllers\Api\StoryController;
use App\Http\Controllers\Api\SurveyController;
use Illuminate\Support\Facades\Route;

// Connection diagnostics (see HealthController). Kept out of the main
// group below on purpose: `not-banned` would answer a banned account with
// a 403 here too, and "the server is unreachable" then looks exactly like
// "my account is blocked" — the one distinction this endpoint exists to
// make. Still rate-limited like everything else.
Route::prefix('v1')->middleware(['throttle:api', 'sentry-context'])->group(function () {
    Route::get('/', [HealthController::class, 'index']);
    Route::get('/health', [HealthController::class, 'index']);

    // The one endpoint that can't require a token, because it's what hands
    // one out. Everything else in the API is behind `auth:sanctum` below.
    Route::post('/auth/session', [AuthController::class, 'session']);
});

// Everything here is auto-prefixed with /api by bootstrap/app.php's
// withRouting(api: ...) — adding /v1 here matches docs/API_CONTRACT.md's
// base path exactly. `throttle:api` (config in bootstrap/app.php) applies
// rate limiting across this whole group.
//
// Middleware order matters: throttle first so an unauthenticated flood is
// rejected before it costs a token lookup, then `auth:sanctum` to resolve
// the real user, then `not-banned` — which needs that user to already be
// resolved to know whose ban to check.
//
// Admin routes are inside this group, so they now reject a request with no
// token at all. They still don't check *which* user it is — role/permission
// enforcement is the next milestone (docs/AUDIT_GERCEK_URUN.md §12, P1-5).
Route::prefix('v1')->middleware(['throttle:api', 'auth:sanctum', 'not-banned', 'sentry-context'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/me', [ProfileController::class, 'me']);
    Route::get('/me/quests', [ProfileController::class, 'quests']);
    Route::get('/me/activity', [ProfileController::class, 'activity']);

    Route::post('/moderation/check-image', [ModerationController::class, 'checkImage']);

    Route::get('/places', [PlaceController::class, 'index']);
    Route::get('/places/{id}/availability', [PlaceController::class, 'availability']);
    Route::get('/places/{id}', [PlaceController::class, 'show']);
    Route::get('/places/{id}/reviews', [PlaceController::class, 'reviews']);
    Route::post('/places/{id}/reviews', [PlaceController::class, 'addReview']);
    Route::post('/places/{id}/report', [PlaceController::class, 'report']);

    Route::get('/events', [EventController::class, 'index']);
    // Static /events/mine must be registered before the /events/{id}
    // wildcard below — Laravel matches routes in registration order, so
    // the wildcard would otherwise swallow "mine" as an {id} and 404
    // (found via a real failing GET /events/mine request).
    Route::post('/events/mine', [EventController::class, 'createOwnActivity']);
    Route::get('/events/mine', [EventController::class, 'myActivities']);
    Route::get('/events/{id}', [EventController::class, 'show']);
    Route::post('/events/{id}/join', [EventController::class, 'join']);
    Route::post('/events/{id}/join/form', [EventController::class, 'submitForm']);

    Route::get('/feed', [FeedController::class, 'index']);
    Route::post('/feed', [FeedController::class, 'store']);
    Route::post('/feed/{id}/like', [FeedController::class, 'like']);
    Route::post('/feed/{id}/comments', [FeedController::class, 'comment']);
    Route::post('/feed/{id}/report', [FeedController::class, 'report']);

    Route::get('/stories', [StoryController::class, 'index']);
    Route::post('/stories', [StoryController::class, 'store']);

    Route::get('/leaderboard', [LeaderboardController::class, 'index']);

    Route::post('/checkins', [CheckinController::class, 'store']);

    Route::post('/ai/query', [AiController::class, 'query']);

    // Clubs / Sports / Services / Food / Directory / Pages — public reads.
    Route::get('/clubs', [ClubController::class, 'index']);
    Route::get('/clubs/{id}', [ClubController::class, 'show']);
    Route::get('/sports', [SportController::class, 'index']);
    Route::get('/services', [ServiceController::class, 'index']);
    Route::get('/services/{id}', [ServiceController::class, 'show']);
    Route::get('/food-venues', [FoodVenueController::class, 'index']);
    Route::get('/directory', [DirectoryController::class, 'index']);
    Route::get('/pages', [PageController::class, 'index']);
    Route::get('/pages/{slug}', [PageController::class, 'show']);

    // Social graph, saved posts, chat, notifications — real backend
    // versions of SocialGraphStore/SavedPostsStore/ChatStore.
    Route::get('/social/following', [SocialGraphController::class, 'following']);
    Route::get('/social/blocked', [SocialGraphController::class, 'blocked']);
    Route::post('/social/follow', [SocialGraphController::class, 'toggleFollow']);
    Route::post('/social/block', [SocialGraphController::class, 'toggleBlock']);
    Route::get('/saved-posts', [SavedPostController::class, 'index']);
    Route::post('/saved-posts/toggle', [SavedPostController::class, 'toggle']);
    Route::get('/chat/threads', [ChatController::class, 'threads']);
    Route::get('/chat/{peer}/messages', [ChatController::class, 'messages']);
    Route::post('/chat/{peer}/messages', [ChatController::class, 'send']);
    Route::get('/notifications', [NotificationController::class, 'index']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/push-tokens', [PushTokenController::class, 'register']);
    Route::post('/push-tokens/unregister', [PushTokenController::class, 'unregister']);

    // Surveys/polls — active list + vote for the student-facing popup.
    Route::get('/surveys/active', [SurveyController::class, 'activeIndex']);
    Route::post('/surveys/{id}/vote', [SurveyController::class, 'vote']);

    Route::get('/academic-years', [AcademicYearController::class, 'index']);

    Route::middleware('permission:media.manage')->group(function () {
        Route::get('/content/{contentKey}/revisions', [ContentRevisionController::class, 'index']);
        Route::post('/content/{contentKey}/revisions', [ContentRevisionController::class, 'record']);
        Route::get('/content/{contentKey}/draft', [ContentRevisionController::class, 'getDraft']);
        Route::post('/content/{contentKey}/draft', [ContentRevisionController::class, 'saveDraft']);
        Route::delete('/content/{contentKey}/draft', [ContentRevisionController::class, 'deleteDraft']);
    });

    // Media library and content revisions/drafts. These don't live under
    // /admin/* for historical reasons, but only the Admin Panel calls them
    // (features/admin/…), so they're gated like everything else it does
    // rather than by where the path happens to sit.
    Route::middleware('permission:media.manage')->group(function () {
        Route::get('/media', [MediaController::class, 'index']);
        Route::post('/media', [MediaController::class, 'store']);
        Route::post('/media/{id}', [MediaController::class, 'update']);
        Route::post('/media/{id}/delete', [MediaController::class, 'destroy']);
    });

    // --------------------------------------------------------- Admin ---
    //
    // Every route below names the permission it needs
    // (App\Services\GranularPermissions::KEYS). A signed-in student holds
    // none of them and gets a 403; the Flutter UI hiding these buttons is a
    // convenience, not the protection.

    Route::middleware('permission:events.manage')->group(function () {
        Route::post('/admin/events', [AdminEventController::class, 'upsert']);
        Route::post('/admin/events/{id}/delete', [AdminEventController::class, 'destroy']);
        Route::post('/admin/events/{eventId}/participation-types', [AdminEventController::class, 'upsertParticipationType']);
        Route::post('/admin/events/{eventId}/participation-types/{typeId}/delete', [AdminEventController::class, 'destroyParticipationType']);
        Route::get('/admin/events/{eventId}/participants', [AdminEventController::class, 'participants']);
        Route::post('/admin/events/{eventId}/participants/{joinId}/approve', [AdminEventController::class, 'approveParticipant']);
    });

    // Reviewing what students proposed is its own decision from editing the
    // calendar, so it's its own key — someone can be given the review queue
    // without also being handed event creation.
    Route::middleware('permission:pendingActivities.manage')->group(function () {
        Route::get('/admin/events/pending', [AdminEventController::class, 'pendingActivities']);
        Route::post('/admin/events/{id}/approve', [AdminEventController::class, 'approveActivity']);
        Route::post('/admin/events/{id}/reject', [AdminEventController::class, 'rejectActivity']);
    });

    Route::middleware('permission:moderation.moderate')->group(function () {
        Route::get('/admin/reports', [AdminReportController::class, 'index']);
        Route::post('/admin/reports/{id}/resolve', [AdminReportController::class, 'resolve']);
        Route::get('/admin/settings/moderation', [AdminSettingsController::class, 'moderation']);
        Route::post('/admin/settings/moderation', [AdminSettingsController::class, 'setModeration']);
    });

    Route::middleware('permission:stats.view')
        ->get('/admin/stats', [AdminStatsController::class, 'index']);

    Route::middleware('permission:clubs.manage')->group(function () {
        Route::post('/admin/clubs', [ClubController::class, 'upsert']);
        Route::post('/admin/clubs/{id}/delete', [ClubController::class, 'destroy']);
    });
    Route::middleware('permission:sports.manage')->group(function () {
        Route::post('/admin/sports', [SportController::class, 'upsert']);
        Route::post('/admin/sports/{id}/delete', [SportController::class, 'destroy']);
    });
    Route::middleware('permission:services.manage')->group(function () {
        Route::post('/admin/services', [ServiceController::class, 'upsert']);
        Route::post('/admin/services/{id}/delete', [ServiceController::class, 'destroy']);
    });
    Route::middleware('permission:food.manage')->group(function () {
        Route::post('/admin/food-venues', [FoodVenueController::class, 'upsert']);
        Route::post('/admin/food-venues/{id}/delete', [FoodVenueController::class, 'destroy']);
        Route::post('/admin/food-venues/{venueId}/menus', [FoodVenueController::class, 'upsertMenu']);
        Route::post('/admin/food-venues/{venueId}/menus/{date}/delete', [FoodVenueController::class, 'destroyMenu']);
    });
    Route::middleware('permission:directory.manage')->group(function () {
        Route::post('/admin/directory', [DirectoryController::class, 'upsert']);
        Route::post('/admin/directory/{id}/delete', [DirectoryController::class, 'destroy']);
    });
    Route::middleware('permission:pages.manage')->group(function () {
        Route::post('/admin/pages', [PageController::class, 'upsert']);
        Route::post('/admin/pages/{id}/delete', [PageController::class, 'destroy']);
    });

    // Reading and changing who is an admin is the most sensitive thing in
    // here — it's how someone would grant themselves everything else — so
    // it sits in the super-admin-only bucket.
    Route::middleware('permission:users.manage')->group(function () {
        Route::get('/admin/roles', [RoleController::class, 'index']);
        Route::post('/admin/roles', [RoleController::class, 'upsert']);
        Route::post('/admin/roles/{email}/delete', [RoleController::class, 'destroy']);
        Route::get('/admin/settings/site', [AdminSettingsController::class, 'site']);
        Route::post('/admin/settings/site', [AdminSettingsController::class, 'setSite']);
    });

    // Deliberately not behind users.manage: every account calls this for
    // its *own* address at sign-in to find out what it may do. The
    // controller allows exactly that and requires users.manage to look
    // anyone else up — so this stays a self-lookup, not a roster.
    Route::get('/admin/roles/{email}', [RoleController::class, 'roleFor']);

    Route::middleware('permission:activityLog.view')
        ->get('/admin/audit-log', [AuditLogController::class, 'index']);

    Route::middleware('permission:surveys.manage')->group(function () {
        Route::get('/admin/surveys', [SurveyController::class, 'adminIndex']);
        Route::post('/admin/surveys', [SurveyController::class, 'upsert']);
        Route::post('/admin/surveys/{id}/delete', [SurveyController::class, 'destroy']);
    });

    Route::middleware('permission:academicYears.manage')->group(function () {
        Route::post('/admin/academic-years', [AcademicYearController::class, 'upsert']);
        Route::post('/admin/academic-years/{id}/delete', [AcademicYearController::class, 'destroy']);
    });

    Route::middleware('permission:email.send')->group(function () {
        Route::get('/admin/email-logs', [AdminEmailController::class, 'logs']);
        Route::post('/admin/email-logs/{id}/retry', [AdminEmailController::class, 'retry']);
        Route::post('/admin/email/bulk', [AdminEmailController::class, 'bulk']);
    });
});
