<?php

use App\Http\Controllers\Api\Admin\AcademicStaffController as AdminAcademicStaffController;
use App\Http\Controllers\Api\Admin\EmailController as AdminEmailController;
use App\Http\Controllers\Api\Admin\EventController as AdminEventController;
use App\Http\Controllers\Api\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Api\Admin\StatsController as AdminStatsController;
use App\Http\Controllers\Api\Admin\UserController as AdminUserController;
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

// Everything here is auto-prefixed with /api by bootstrap/app.php's
// withRouting(api: ...) — adding /v1 here matches docs/API_CONTRACT.md's
// base path exactly. `throttle:api` (config in bootstrap/app.php) applies
// rate limiting across this whole group.
//
// /auth/session is the one real exception — obviously unauthenticated,
// since its whole job is to hand out the token everything else requires.
Route::prefix('v1')->middleware(['throttle:api'])->group(function () {
    Route::post('/auth/session', [AuthController::class, 'session']);
});

// Real, per-request authentication (docs/EKSIKLER.md "Gerçek JWT/session
// authentication") — every route below requires the real Sanctum bearer
// token AuthController::session() issues; ApiResponds::currentUser()
// resolves from it, not a hardcoded single account.
Route::prefix('v1')->middleware(['throttle:api', 'auth:sanctum', 'not-banned'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/me', [ProfileController::class, 'me']);
    Route::get('/me/quests', [ProfileController::class, 'quests']);
    Route::get('/me/activity', [ProfileController::class, 'activity']);
    Route::get('/me/xp-transactions', [ProfileController::class, 'xpTransactions']);

    // Every signed-in account (not just admins) needs to look up its own
    // role right after login — see app.dart's _finishSignIn() — so this
    // one /admin/roles/{email} read deliberately stays outside the
    // manageSiteSettings gate below, unlike the rest of /admin/roles/*.
    Route::get('/admin/roles/{email}', [RoleController::class, 'roleFor']);

    Route::post('/moderation/check-image', [ModerationController::class, 'checkImage']);

    Route::get('/places', [PlaceController::class, 'index']);
    Route::get('/places/density', [PlaceController::class, 'density']);
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

    Route::get('/content/{contentKey}/revisions', [ContentRevisionController::class, 'index']);
    Route::post('/content/{contentKey}/revisions', [ContentRevisionController::class, 'record']);
    Route::get('/content/{contentKey}/draft', [ContentRevisionController::class, 'getDraft']);
    Route::post('/content/{contentKey}/draft', [ContentRevisionController::class, 'saveDraft']);
    Route::delete('/content/{contentKey}/draft', [ContentRevisionController::class, 'deleteDraft']);

    Route::get('/media', [MediaController::class, 'index']);
    Route::post('/media', [MediaController::class, 'store']);
    Route::post('/media/{id}', [MediaController::class, 'update']);
    Route::post('/media/{id}/delete', [MediaController::class, 'destroy']);

    // --------------------------------------------------------- Admin ---
    // Real route-level RBAC (docs/EKSIKLER.md "RBAC permission
    // enforcement") — mirrors UserRoleLabel's canManageContent/
    // canModerate/canManageSiteSettings in campus_models.dart exactly.
    // See EnsurePermission for the role->permission map.

    Route::middleware('permission:manageContent')->group(function () {
        Route::post('/admin/events', [AdminEventController::class, 'upsert']);
        Route::post('/admin/events/{id}/delete', [AdminEventController::class, 'destroy']);
        Route::get('/admin/events/pending', [AdminEventController::class, 'pendingActivities']);
        Route::post('/admin/events/{id}/approve', [AdminEventController::class, 'approveActivity']);
        Route::post('/admin/events/{id}/reject', [AdminEventController::class, 'rejectActivity']);
        Route::post('/admin/events/{eventId}/participation-types', [AdminEventController::class, 'upsertParticipationType']);
        Route::post('/admin/events/{eventId}/participation-types/{typeId}/delete', [AdminEventController::class, 'destroyParticipationType']);
        Route::get('/admin/events/{eventId}/participants', [AdminEventController::class, 'participants']);
        Route::post('/admin/events/{eventId}/participants/{joinId}/approve', [AdminEventController::class, 'approveParticipant']);

        Route::post('/admin/clubs', [ClubController::class, 'upsert']);
        Route::post('/admin/clubs/{id}/delete', [ClubController::class, 'destroy']);
        Route::post('/admin/sports', [SportController::class, 'upsert']);
        Route::post('/admin/sports/{id}/delete', [SportController::class, 'destroy']);
        Route::post('/admin/services', [ServiceController::class, 'upsert']);
        Route::post('/admin/services/{id}/delete', [ServiceController::class, 'destroy']);
        Route::post('/admin/food-venues', [FoodVenueController::class, 'upsert']);
        Route::post('/admin/food-venues/{id}/delete', [FoodVenueController::class, 'destroy']);
        Route::post('/admin/food-venues/{venueId}/menus', [FoodVenueController::class, 'upsertMenu']);
        Route::post('/admin/food-venues/{venueId}/menus/{date}/delete', [FoodVenueController::class, 'destroyMenu']);
        Route::post('/admin/directory', [DirectoryController::class, 'upsert']);
        Route::post('/admin/directory/{id}/delete', [DirectoryController::class, 'destroy']);
        Route::post('/admin/pages', [PageController::class, 'upsert']);
        Route::post('/admin/pages/{id}/delete', [PageController::class, 'destroy']);

        Route::get('/admin/surveys', [SurveyController::class, 'adminIndex']);
        Route::post('/admin/surveys', [SurveyController::class, 'upsert']);
        Route::post('/admin/surveys/{id}/delete', [SurveyController::class, 'destroy']);

        Route::post('/admin/academic-years', [AcademicYearController::class, 'upsert']);
        Route::post('/admin/academic-years/{id}/delete', [AcademicYearController::class, 'destroy']);
    });

    Route::middleware('permission:moderate')->group(function () {
        Route::get('/admin/reports', [AdminReportController::class, 'index']);
        Route::post('/admin/reports/{id}/resolve', [AdminReportController::class, 'resolve']);
    });

    Route::middleware('permission:manageSiteSettings')->group(function () {
        Route::get('/admin/settings/moderation', [AdminSettingsController::class, 'moderation']);
        Route::post('/admin/settings/moderation', [AdminSettingsController::class, 'setModeration']);
        Route::get('/admin/settings/checkin-radius', [AdminSettingsController::class, 'checkinRadius']);
        Route::post('/admin/settings/checkin-radius', [AdminSettingsController::class, 'setCheckinRadius']);

        Route::get('/admin/roles', [RoleController::class, 'index']);
        Route::post('/admin/roles', [RoleController::class, 'upsert']);
        Route::post('/admin/roles/{email}/delete', [RoleController::class, 'destroy']);

        Route::get('/admin/users', [AdminUserController::class, 'index']);
        Route::get('/admin/users/banned', [AdminUserController::class, 'bannedIndex']);
        Route::get('/admin/users/{id}', [AdminUserController::class, 'show']);
        Route::post('/admin/users', [AdminUserController::class, 'store']);
        Route::post('/admin/users/{id}', [AdminUserController::class, 'update']);
        Route::post('/admin/users/{id}/unban', [AdminUserController::class, 'unban']);

        Route::get('/admin/settings/auth', [AdminSettingsController::class, 'auth']);
        Route::post('/admin/settings/auth', [AdminSettingsController::class, 'setAuth']);
        Route::get('/admin/settings/xp', [AdminSettingsController::class, 'xp']);
        Route::post('/admin/settings/xp', [AdminSettingsController::class, 'setXp']);
    });

    Route::middleware('permission:viewAdmin')->group(function () {
        Route::get('/admin/stats', [AdminStatsController::class, 'index']);
        Route::get('/admin/audit-log', [AuditLogController::class, 'index']);
        Route::get('/admin/email-logs', [AdminEmailController::class, 'logs']);
        Route::post('/admin/email-logs/{id}/retry', [AdminEmailController::class, 'retry']);
        Route::post('/admin/email/bulk', [AdminEmailController::class, 'bulk']);
        Route::get('/admin/academic-staff', [AdminAcademicStaffController::class, 'index']);
    });
});
