<?php

use App\Http\Controllers\Api\Admin\AchievementDefinitionController;
use App\Http\Controllers\Api\Admin\EmailController as AdminEmailController;
use App\Http\Controllers\Api\Admin\EventController as AdminEventController;
use App\Http\Controllers\Api\Admin\EventPosterDraftController;
use App\Http\Controllers\Api\Admin\FeedModerationController;
use App\Http\Controllers\Api\Admin\ModerationEventsController;
use App\Http\Controllers\Api\Admin\ModerationQueueController;
use App\Http\Controllers\Api\Admin\ReportController as AdminReportController;
use App\Http\Controllers\Api\Admin\WordpressVersionController;
use App\Http\Controllers\Api\Admin\SettingsController as AdminSettingsController;
use App\Http\Controllers\Api\Admin\StatsController as AdminStatsController;
use App\Http\Controllers\Api\Admin\SystemHealthController;
use App\Http\Controllers\Api\AchievementController;
use App\Http\Controllers\Api\AcademicYearController;
use App\Http\Controllers\Api\AiController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AskConversationController;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\EntraAuthController;
use App\Http\Controllers\Api\CareerController;
use App\Http\Controllers\Api\ChatController;
use App\Http\Controllers\Api\CheckinController;
use App\Http\Controllers\Api\ClubController;
use App\Http\Controllers\Api\ClubMemberController;
use App\Http\Controllers\Api\ConsultationController;
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
use App\Http\Controllers\Api\ApplicationQuestionController;
use App\Http\Controllers\Api\ParticipationApplicationController;
use App\Http\Controllers\Api\PlaceController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\RoutingController;
use App\Http\Controllers\Api\SavedPostController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\OnboardingStepController;
use App\Http\Controllers\Api\TourProxyController;
use App\Http\Controllers\Api\ShuttleController;
use App\Http\Controllers\Api\WeatherController;
use App\Http\Controllers\Api\SocialGraphController;
use App\Http\Controllers\Api\SportController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\StoryController;
use App\Http\Controllers\Api\SurveyController;
use App\Http\Controllers\Api\Trainer\ApplicationController as TrainerApplicationController;
use App\Http\Controllers\Api\Trainer\EventController as TrainerEventController;
use App\Http\Controllers\Api\Trainer\RosterController as TrainerRosterController;
use Illuminate\Support\Facades\Route;

// Connection diagnostics (see HealthController). Kept out of the main
// group below on purpose: `not-banned` would answer a banned account with
// a 403 here too, and "the server is unreachable" then looks exactly like
// "my account is blocked" — the one distinction this endpoint exists to
// make. Still rate-limited like everything else.
Route::prefix('v1')->middleware(['throttle:api', 'sentry-context'])->group(function () {
    Route::get('/', [HealthController::class, 'index']);
    Route::get('/health', [HealthController::class, 'index']);
    Route::get('/media/{id}/file', [MediaController::class, 'file']);
    // A short-lived signed URL is issued only in the moderator queue.  It
    // lets a reviewer inspect pending material without ever making that
    // material publicly addressable.
    Route::get('/media/{id}/review-file', [MediaController::class, 'reviewFile'])
        ->middleware('signed')
        ->name('api.media.review-file');
    Route::get('/media/file/{filename}', [MediaController::class, 'fileByName'])
        ->where('filename', '[A-Za-z0-9._-]+');
    // Real fix so 360 tours embed on Flutter web instead of only opening in
    // an external tab — see TourProxyController's doc comment.
    Route::get('/tour-proxy/{path}', [TourProxyController::class, 'show'])->where('path', '.*');

    // The one endpoint that can't require a token, because it's what hands
    // one out. Everything else in the API is behind `auth:sanctum` below.
    Route::post('/auth/session', [AuthController::class, 'session']);
    Route::get('/auth/entra/config', [EntraAuthController::class, 'config']);
    Route::post('/auth/entra', [EntraAuthController::class, 'session']);
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
// Admin routes are inside this group, so they reject a request with no
// token at all. `permission:{key}` on each admin/trainer route then checks
// *which* user it is against GranularPermissions / role_assignments.
Route::prefix('v1')->middleware(['throttle:api', 'auth:sanctum', 'not-banned', 'sentry-context'])->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);

    Route::get('/me', [ProfileController::class, 'me']);
    Route::post('/me/profile', [ProfileController::class, 'updateBio']);
    Route::get('/me/settings', [ProfileController::class, 'settings']);
    Route::post('/me/settings', [ProfileController::class, 'updateSettings']);
    Route::get('/me/quests', [ProfileController::class, 'quests']);
    Route::get('/me/achievements', [AchievementController::class, 'index']);
    Route::get('/me/activity', [ProfileController::class, 'activity']);
    Route::get('/me/onboarding', [ProfileController::class, 'onboarding']);
    Route::post('/me/onboarding/{stepId}', [ProfileController::class, 'setOnboardingStep']);
    Route::get('/onboarding-steps', [OnboardingStepController::class, 'index']);
    Route::get('/me/career-profile', [CareerController::class, 'profile']);
    Route::post('/me/career-profile', [CareerController::class, 'updateProfile']);
    Route::post('/me/career-profile/cv', [CareerController::class, 'uploadCv']);
    Route::get('/me/career-profile/cv', [CareerController::class, 'downloadOwnCv']);
    Route::post('/me/career-profile/cv/delete', [CareerController::class, 'deleteCv']);
    Route::get('/me/career-applications', [CareerController::class, 'myApplications']);
    Route::get('/me/consultation-applications', [ConsultationController::class, 'myApplications']);

    Route::get('/career/opportunities', [CareerController::class, 'opportunities']);
    Route::get('/career/opportunities/{id}', [CareerController::class, 'showOpportunity']);
    Route::post('/career/opportunities/{id}/apply', [CareerController::class, 'apply']);
    Route::get('/consultations', [ConsultationController::class, 'index']);
    Route::get('/consultations/{id}', [ConsultationController::class, 'show']);
    Route::post('/consultations/{id}/apply', [ConsultationController::class, 'apply']);

    Route::post('/moderation/check-image', [ModerationController::class, 'checkImage']);

    Route::get('/places', [PlaceController::class, 'index']);
    Route::get('/places/{id}/availability', [PlaceController::class, 'availability']);
    Route::get('/places/{id}', [PlaceController::class, 'show']);
    Route::post('/places/{id}/cover', [PlaceController::class, 'setCover'])
        ->middleware('permission:places.manage');
    Route::get('/places/{id}/reviews', [PlaceController::class, 'reviews']);
    Route::post('/places/{id}/reviews', [PlaceController::class, 'addReview']);
    Route::post('/places/{id}/report', [PlaceController::class, 'report']);
    Route::get('/places/{id}/workshop', [PlaceController::class, 'workshop']);
    Route::post('/places/{id}/workshop/posts', [PlaceController::class, 'addWorkshopPost']);

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
    Route::post('/feed/{id}', [FeedController::class, 'update']);
    Route::post('/feed/{id}/delete', [FeedController::class, 'destroy']);
    Route::post('/feed/{id}/like', [FeedController::class, 'like']);
    Route::post('/feed/{id}/comments', [FeedController::class, 'comment']);
    Route::post('/feed/{id}/report', [FeedController::class, 'report']);
    Route::post('/feed/{id}/pin', [FeedController::class, 'pin'])->middleware('permission:pages.manage');
    Route::post('/feed/{id}/unpin', [FeedController::class, 'unpin'])->middleware('permission:pages.manage');

    Route::get('/stories', [StoryController::class, 'index']);
    Route::post('/stories', [StoryController::class, 'store']);
    Route::post('/stories/{id}/view', [StoryController::class, 'view']);
    Route::get('/stories/{id}/viewers', [StoryController::class, 'viewers']);
    Route::post('/stories/{id}/delete', [StoryController::class, 'destroy']);

    Route::get('/leaderboard', [LeaderboardController::class, 'index']);

    Route::post('/checkins', [CheckinController::class, 'store']);

    Route::post('/ai/query', [AiController::class, 'query'])->middleware('throttle:ai');
    Route::get('/ask/conversations', [AskConversationController::class, 'index']);
    Route::get('/ask/conversations/{id}', [AskConversationController::class, 'show']);
    Route::post('/ask/conversations/{id}/delete', [AskConversationController::class, 'destroy']);
    Route::post('/routing/directions', [RoutingController::class, 'directions']);

    // Clubs / Sports / Services / Food / Directory / Pages — public reads.
    Route::get('/clubs', [ClubController::class, 'index']);
    Route::get('/clubs/{id}', [ClubController::class, 'show']);
    Route::post('/clubs/{id}/join', [ClubMemberController::class, 'join']);
    Route::post('/clubs/{id}/leave', [ClubMemberController::class, 'leave']);
    Route::get('/sports', [SportController::class, 'index']);
    Route::get('/services', [ServiceController::class, 'index']);
    Route::get('/services/{id}', [ServiceController::class, 'show']);
    Route::get('/food-venues', [FoodVenueController::class, 'index']);
    Route::get('/shuttle-routes', [ShuttleController::class, 'index']);
    Route::get('/weather', [WeatherController::class, 'current']);
    Route::get('/directory/buildings', [DirectoryController::class, 'buildings']);
    Route::get('/directory/buildings/{building}/floors', [DirectoryController::class, 'floors']);
    Route::get('/directory/buildings/{building}/floors/{floor}/rooms', [DirectoryController::class, 'rooms']);
    Route::get('/directory', [DirectoryController::class, 'index']);
    Route::get('/pages', [PageController::class, 'index']);
    Route::get('/pages/{slug}', [PageController::class, 'show']);

    // Social graph, saved posts, chat, notifications — real backend
    // versions of SocialGraphStore/SavedPostsStore/ChatStore.
    Route::get('/social/following', [SocialGraphController::class, 'following']);
    Route::get('/social/followers', [SocialGraphController::class, 'followers']);
    Route::get('/social/friends', [SocialGraphController::class, 'friends']);
    Route::get('/social/users/{id}', [SocialGraphController::class, 'showUser']);
    Route::get('/social/blocked', [SocialGraphController::class, 'blocked']);
    Route::get('/social/follow-requests', [SocialGraphController::class, 'followRequests']);
    Route::post('/social/follow-requests/accept', [SocialGraphController::class, 'acceptFollowRequest']);
    Route::post('/social/follow-requests/decline', [SocialGraphController::class, 'declineFollowRequest']);
    Route::post('/social/follow', [SocialGraphController::class, 'toggleFollow']);
    Route::post('/social/block', [SocialGraphController::class, 'toggleBlock']);
    Route::post('/social/report-user', [SocialGraphController::class, 'reportUser']);
    Route::get('/saved-posts', [SavedPostController::class, 'index']);
    Route::post('/saved-posts/toggle', [SavedPostController::class, 'toggle']);
    Route::get('/club-memberships', [ClubMemberController::class, 'index']);
    Route::get('/staff', [StaffController::class, 'index']);
    Route::get('/me/applications', [ParticipationApplicationController::class, 'mine']);
    Route::get('/me/applications/{id}/history', [ParticipationApplicationController::class, 'history']);
    Route::post('/me/applications/{id}/detail', [ParticipationApplicationController::class, 'submitDetail']);
    Route::post('/applications', [ParticipationApplicationController::class, 'store']);
    Route::get('/application-questions', [ApplicationQuestionController::class, 'index']);
    Route::get('/staff/{staffId}/slots', [AppointmentController::class, 'slots']);
    Route::get('/me/appointments', [AppointmentController::class, 'mine']);
    Route::get('/appointments/{id}', [AppointmentController::class, 'show']);
    Route::post('/appointments', [AppointmentController::class, 'book']);
    Route::post('/appointments/{id}/cancel', [AppointmentController::class, 'cancel']);
    Route::get('/chat/threads', [ChatController::class, 'threads']);
    Route::get('/chat/prefs', [ChatController::class, 'prefs']);
    Route::post('/chat/prefs/toggle', [ChatController::class, 'togglePref']);
    Route::get('/chat/groups', [ChatController::class, 'groups']);
    Route::post('/chat/groups', [ChatController::class, 'createGroup']);
    Route::post('/chat/groups/{id}/leave', [ChatController::class, 'leaveGroup']);
    Route::post('/chat/groups/{id}/prefs/toggle', [ChatController::class, 'toggleGroupPref']);
    Route::post('/chat/groups/{id}/report', [ChatController::class, 'reportGroup']);
    Route::get('/chat/groups/{id}/messages', [ChatController::class, 'groupMessages']);
    Route::post('/chat/groups/{id}/messages', [ChatController::class, 'sendGroupMessage']);
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

    // Personal gallery — authenticated owner only. Registered before the
    // admin /media/{id} routes so "mine" is not captured as an id.
    Route::get('/media/mine', [MediaController::class, 'mine']);
    Route::post('/media/mine', [MediaController::class, 'storeMine']);
    Route::post('/media/mine/{id}/delete', [MediaController::class, 'destroyMine']);

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
        Route::post('/admin/events/draft-from-poster', [EventPosterDraftController::class, 'store']);
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
        Route::post('/admin/reviews/{id}/delete', [AdminReportController::class, 'destroyReview']);
        Route::get('/admin/moderation/queue', [ModerationQueueController::class, 'index']);
        Route::post('/admin/moderation/queue/{id}/resolve', [ModerationQueueController::class, 'resolve']);
        Route::get('/admin/settings/moderation', [AdminSettingsController::class, 'moderation']);
        Route::post('/admin/settings/moderation', [AdminSettingsController::class, 'setModeration']);
        Route::get('/admin/moderation/posts', [FeedModerationController::class, 'index']);
        Route::post('/admin/moderation/posts/{id}/approve', [FeedModerationController::class, 'approve']);
        Route::post('/admin/moderation/posts/{id}/reject', [FeedModerationController::class, 'reject']);

        // Automated-decision audit trail, offender standing, and the manual
        // overrides an admin needs when the system gets one wrong.
        Route::get('/admin/moderation/events', [ModerationEventsController::class, 'index']);
        Route::get('/admin/moderation/users', [ModerationEventsController::class, 'users']);
        Route::get('/admin/moderation/policy', [ModerationEventsController::class, 'policy']);
        Route::post('/admin/moderation/events/{id}/remove-strike', [ModerationEventsController::class, 'removeStrike']);
        Route::post('/admin/moderation/users/{userId}/ban', [ModerationEventsController::class, 'setBan']);
    });

    Route::middleware('permission:stats.view')
        ->get('/admin/stats', [AdminStatsController::class, 'index']);

    Route::middleware('permission:clubs.manage')->group(function () {
        Route::post('/admin/clubs', [ClubController::class, 'upsert']);
        Route::post('/admin/clubs/{id}/delete', [ClubController::class, 'destroy']);
    });
    Route::middleware('permission:places.manage')->group(function () {
        Route::post('/admin/places', [PlaceController::class, 'upsert']);
        Route::post('/admin/places/{id}/delete', [PlaceController::class, 'destroy']);
        Route::post('/admin/places/{id}/workshop/equipment', [PlaceController::class, 'upsertWorkshopEquipment']);
        Route::post('/admin/places/{id}/workshop/equipment/{itemId}/delete', [PlaceController::class, 'destroyWorkshopEquipment']);
        Route::post('/admin/places/{id}/workshop/posts/{postId}/delete', [PlaceController::class, 'destroyWorkshopPost']);
    });
    Route::middleware('permission:sports.manage')->group(function () {
        Route::post('/admin/sports', [SportController::class, 'upsert']);
        Route::post('/admin/sports/{id}/delete', [SportController::class, 'destroy']);
    });
    Route::middleware('permission:services.manage')->group(function () {
        Route::post('/admin/services', [ServiceController::class, 'upsert']);
        Route::post('/admin/services/{id}/delete', [ServiceController::class, 'destroy']);
    });
    Route::middleware('permission:shuttle.manage')->group(function () {
        Route::post('/admin/shuttle-routes', [ShuttleController::class, 'upsert']);
        Route::post('/admin/shuttle-routes/{id}/delete', [ShuttleController::class, 'destroy']);
    });
    Route::middleware('permission:onboarding.manage')->group(function () {
        Route::get('/admin/onboarding-steps', [OnboardingStepController::class, 'adminIndex']);
        Route::post('/admin/onboarding-steps', [OnboardingStepController::class, 'upsert']);
        Route::post('/admin/onboarding-steps/{id}/delete', [OnboardingStepController::class, 'destroy']);
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
        Route::post('/admin/feed', [FeedController::class, 'storeOfficial']);
    });
    Route::middleware('permission:career.manage')->group(function () {
        Route::get('/admin/career/opportunities', [CareerController::class, 'adminOpportunities']);
        Route::post('/admin/career/opportunities', [CareerController::class, 'upsertOpportunity']);
        Route::post('/admin/career/opportunities/{id}/delete', [CareerController::class, 'destroyOpportunity']);
        Route::get('/admin/career/applications', [CareerController::class, 'adminApplications']);
        Route::post('/admin/career/applications/{id}', [CareerController::class, 'updateApplication']);
        Route::get('/admin/career/applications/{id}/cv', [CareerController::class, 'downloadApplicationCv']);
        Route::get('/admin/consultations', [ConsultationController::class, 'adminIndex']);
        Route::post('/admin/consultations', [ConsultationController::class, 'upsert']);
        Route::post('/admin/consultations/{id}/delete', [ConsultationController::class, 'destroy']);
        Route::get('/admin/consultation-applications', [ConsultationController::class, 'adminApplications']);
        Route::post('/admin/consultation-applications/{id}', [ConsultationController::class, 'updateApplication']);
    });

    Route::middleware('permission:staff.manage')->group(function () {
        Route::get('/admin/staff', [StaffController::class, 'adminIndex']);
        Route::post('/admin/staff', [StaffController::class, 'upsert']);
        Route::post('/admin/staff/{id}/delete', [StaffController::class, 'destroy']);
    });

    Route::middleware('permission:applications.manage')->group(function () {
        Route::get('/admin/applications', [ParticipationApplicationController::class, 'adminIndex']);
        Route::post('/admin/applications/{id}/approve', [ParticipationApplicationController::class, 'approve']);
        Route::post('/admin/applications/{id}/reject', [ParticipationApplicationController::class, 'reject']);
        Route::post('/admin/applications/{id}/revise', [ParticipationApplicationController::class, 'requestRevision']);

        Route::get('/admin/application-questions', [ApplicationQuestionController::class, 'adminIndex']);
        Route::post('/admin/application-questions', [ApplicationQuestionController::class, 'upsert']);
        Route::post('/admin/application-questions/{id}/delete', [ApplicationQuestionController::class, 'destroy']);
    });

    Route::middleware('permission:appointments.manage')->group(function () {
        Route::get('/admin/appointments', [AppointmentController::class, 'adminIndex']);
        Route::post('/admin/appointments/{id}', [AppointmentController::class, 'adminUpdate']);
        Route::post('/admin/staff/{staffId}/slots', [AppointmentController::class, 'upsertSlot']);
        Route::post('/admin/staff/{staffId}/slots/{slotId}/delete', [AppointmentController::class, 'destroySlot']);
    });

    Route::middleware('permission:achievements.manage')->group(function () {
        Route::get('/admin/achievements', [AchievementDefinitionController::class, 'index']);
        Route::post('/admin/achievements', [AchievementDefinitionController::class, 'upsert']);
        Route::post('/admin/achievements/{id}/delete', [AchievementDefinitionController::class, 'destroy']);
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
        Route::get('/admin/wordpress/versions', [WordpressVersionController::class, 'index']);
        Route::post('/admin/wordpress/versions', [WordpressVersionController::class, 'store']);
        Route::get('/admin/system-health', [SystemHealthController::class, 'index']);
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

    // Trainer Panel: department heads publishing events for their own
    // department only. Two gates stack — `permission:events.manageOwnDepartment`
    // answers "is this account provisioned as a trainer at all," then
    // `department-head` (EnsureDepartmentHead) resolves which real
    // department, from the StaffProfile linked to this account, never
    // from client input.
    Route::prefix('trainer')
        ->middleware(['permission:events.manageOwnDepartment', 'department-head'])
        ->group(function () {
            Route::get('/events', [TrainerEventController::class, 'index']);
            Route::post('/events', [TrainerEventController::class, 'upsert']);
            Route::post('/events/{id}/delete', [TrainerEventController::class, 'destroy']);
            Route::get('/events/{eventId}/participants', [TrainerEventController::class, 'participants']);
            Route::post('/events/{eventId}/participants/{joinId}/approve', [TrainerEventController::class, 'approveParticipant']);

            Route::get('/applications', [TrainerApplicationController::class, 'index']);
            Route::post('/applications/{id}/approve', [TrainerApplicationController::class, 'approve']);
            Route::post('/applications/{id}/reject', [TrainerApplicationController::class, 'reject']);
            Route::post('/applications/{id}/revise', [TrainerApplicationController::class, 'revise']);

            Route::get('/roster', [TrainerRosterController::class, 'index']);
        });
});
