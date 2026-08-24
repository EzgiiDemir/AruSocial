import 'dart:typed_data';

import '../config/campus_life_config.dart';
import '../models/academic_year.dart';
import '../models/admin_page.dart';
import '../models/admin_stats.dart';
import '../models/audit_log_entry.dart';
import '../models/campus_models.dart';
import '../models/chat_message.dart';
import '../models/content_block.dart';
import '../models/content_revision.dart';
import '../models/media_item.dart';
import '../models/event_participant.dart';
import '../models/inbox_notification.dart';
import '../models/email_log.dart';
import '../models/page_slice.dart';
import '../models/role_assignment.dart';
import '../models/survey.dart';
import 'site_settings_store.dart';

/// Real outcome of a [CampusRepository.joinEvent] call — mirrors the
/// backend's `participationStatus` response so the join popup can show
/// what actually happened instead of a generic "success" toast.
class EventJoinResult {
  final bool alreadyJoined;
  final bool clubEmailSent;
  final bool formEmailSent;

  /// Whether the student has actually completed the katılım formu yet —
  /// distinct from [formEmailSent] (which only means the email went out).
  /// The join popup uses this to offer a real in-app "formu tamamla" step,
  /// since completing the form is what makes the join actionable for the
  /// organizer (see `EventController::submitForm`).
  final bool formSubmitted;

  /// Whether this repository has a real email pipeline at all — false in
  /// Mock mode, which has no email system to report on. Lets the join
  /// popup distinguish "not applicable" from "attempted and failed"
  /// instead of showing every mock join as a failed email send.
  final bool emailSupported;

  const EventJoinResult({
    required this.alreadyJoined,
    required this.clubEmailSent,
    required this.formEmailSent,
    this.formSubmitted = false,
    this.emailSupported = true,
  });
}

/// Real "boş/dolu" conflict (docs/EKSIKLER.md §4): thrown when a place is
/// already booked for the exact date+time slot being requested — both
/// `RestCampusRepository` (translating the backend's real 409
/// `PLACE_UNAVAILABLE`) and `MockCampusRepository` (checking its own event
/// list the same way) throw this instead of silently double-booking.
class PlaceConflictException implements Exception {
  final String reason;
  const PlaceConflictException(this.reason);
  @override
  String toString() => reason;
}

/// One real event already booked at a place on a given day — mirrors
/// `PlaceController::availability()`'s response. Used to show a real
/// "boş/dolu" conflict before submitting (docs/EKSIKLER.md §4).
class PlaceBooking {
  final String eventId;
  final String title;
  final String time;
  final String workflowStatus;

  const PlaceBooking({
    required this.eventId,
    required this.title,
    required this.time,
    required this.workflowStatus,
  });

  factory PlaceBooking.fromJson(Map<String, dynamic> json) => PlaceBooking(
        eventId: json['eventId'] as String,
        title: json['title'] as String,
        time: json['time'] as String,
        workflowStatus: json['workflowStatus'] as String,
      );
}

abstract class CampusRepository {
  Future<CampusUser> getMe();
  Future<List<CampusPlace>> getPlaces();
  /// [includeUnpublished] shows drafts and not-yet-published/expired events
  /// too — only the Admin Panel should pass `true`; every normal screen
  /// should only ever see what's actually live right now.
  /// [academicYearId] filters to events tied to one real academic year —
  /// null means no filter (every year, matching current behavior).
  Future<List<CampusEvent>> getEvents({bool includeUnpublished = false, String? academicYearId});
  Future<List<Quest>> getQuests();
  Future<List<FeedPost>> getFeed();
  Future<PageSlice<FeedPost>> getFeedPage({int page = 1, int perPage = 20});
  Future<void> createPost(String text,
      {String? imageUrl,
      Uint8List? imageBytes,
      PostVisibility visibility = PostVisibility.everyone,
      PostCategory postType = PostCategory.normal,
      String? courseTag,
      String? locationTag});
  /// Toggles the signed-in account's like. The returned post is the
  /// backend's own view of `likes` / `likedByMe` after the write — the
  /// caller must not invent those numbers locally and then assume they
  /// stuck.
  Future<FeedPost> toggleLike(String postId);
  Future<FeedPost> addComment(String postId, String text);
  Future<void> reportPost(String postId, String reason);
  Future<List<CampusStory>> getStories();
  Future<void> addStory(
      {String? text,
      Uint8List? imageBytes,
      int? backgroundColorValue,
      PostVisibility visibility = PostVisibility.everyone});
  Future<List<Review>> getReviews(String placeId);
  Future<void> addReview(String placeId, int rating, String comment);
  Future<void> reportPlace(String placeId, String reason);
  /// Real, fully server-side vision-moderation check (docs/EKSIKLER.md
  /// §26): uploads [bytes] to the backend, which scans them with an API
  /// key that only ever lives server-side (never sent to, or configured
  /// on, this device) and records a real strike if flagged. Throws
  /// [ContentModerationException] if the image is rejected. Mock mode has
  /// no backend to scan with, so it always allows — honest, not faked.
  Future<void> checkImageModeration(Uint8List bytes, {String mimeType = 'image/jpeg'});
  Future<void> checkIn(String placeId, {bool visibleToOthers = true});
  /// [participationTypeId] selects one of the event's real, admin-defined
  /// participation options (if it has any — see [CampusEvent.participationTypes]).
  /// The returned status reflects what the backend actually did: whether the
  /// club-organizer email and the student's own confirmation email were sent.
  Future<EventJoinResult> joinEvent(String eventId, {String? participationTypeId});
  /// Real, in-app completion of the katılım formu the student receives by
  /// email after joining — this is the gate that actually makes the join
  /// reviewable by the organizer (see [EventJoinResult.formSubmitted]).
  Future<EventJoinResult> submitEventJoinForm(String eventId);
  Future<String> askGuide(String prompt);
  Future<List<ActivityItem>> getMyActivity();
  Future<List<LeaderboardEntry>> getLeaderboard();

  // Admin: content management (events) and moderation. Gated in the UI by
  // `UserRole.canManageContent` / `canModerate` — see README roadmap for
  // why this is local-only rather than backed by real Entra roles.
  Future<void> upsertEvent(CampusEvent event);
  Future<void> deleteEvent(String id);
  Future<List<ModerationReport>> getReports();
  Future<void> resolveReport(String id, ModerationAction action);

  /// Whether a real image-moderation API key is configured server-side
  /// (docs/EKSIKLER.md §26) — never the raw key itself, which the client
  /// never sees. Mock mode has no such backend setting, so it's always
  /// false there — honest, not faked.
  Future<bool> getImageModerationConfigured();
  /// Sets (or, with an empty string, clears) the server-side image-
  /// moderation API key. Returns the new configured state.
  Future<bool> setImageModerationApiKey(String apiKey);

  /// Public Entra client config + WordPress site URL. REST hits
  /// `GET/POST /admin/settings/site` (`users.manage`). The WordPress
  /// token is write-only: send it to update, omit it to leave the stored
  /// secret alone, send `''` to clear. GET never includes the token.
  /// Mock mode keeps [SiteSettingsStore] so `USE_REST_API=false` still
  /// works offline.
  Future<SiteSettings> getSiteSettings();
  Future<SiteSettings> updateSiteSettings({
    EntraSiteConfig? entra,
    String? wordpressSiteUrl,
    String? wordpressApiToken,
  });

  /// Real, live-aggregated usage statistics for the Admin Panel's
  /// İstatistikler tab (check-ins, most-visited places, event
  /// participation, content/survey/email counts) — see [AdminStats].
  /// [days] controls the check-in daily-trend window. Mock mode computes
  /// the same shape from its own in-memory state, so the tab works the
  /// same way in either mode.
  Future<AdminStats> getAdminStats({int days = 14});

  // Clubs/Sports: real content, editable by admins. In Rest mode these are
  // genuinely shared, multi-admin backend rows; Mock mode keeps them as
  // on-device `AdminContentStore` state — see that class's own doc comment.
  Future<List<CampusClub>> getClubs();
  Future<void> upsertClub(CampusClub club);
  Future<void> deleteClub(String id);
  Future<List<CampusSport>> getSports();
  Future<void> upsertSport(CampusSport sport);
  Future<void> deleteSport(String id);
  Future<List<CampusService>> getServices();
  Future<void> upsertService(CampusService service);
  Future<void> deleteService(String id);

  // Food venues + per-day menus. Rest mode hits GET /food-venues and the
  // existing /admin/food-venues* writes; Mock mode keeps the on-device
  // AdminContentStore copy so USE_REST_API=false still works offline.
  Future<List<CampusFoodVenue>> getFoodVenues();
  Future<void> upsertFoodVenue(CampusFoodVenue venue);
  Future<void> deleteFoodVenue(String id);
  Future<void> upsertFoodMenu(String venueId, DailyMenu menu);
  Future<void> deleteFoodMenu(String venueId, DateTime date);

  // Media library. Rest mode hits GET/POST /media (multipart upload) and
  // POST /media/{id}[/delete]; Mock mode keeps the on-device
  // MediaLibraryStore (dataUri in SharedPreferences) so USE_REST_API=false
  // still works offline. Binary files never belong in the REST path's prefs.
  Future<List<MediaItem>> getMedia();
  Future<MediaItem> uploadMedia(Uint8List bytes, {required String fileName});
  Future<void> renameMedia(String id, String fileName);
  Future<void> deleteMedia(String id);
  Future<void> markMediaUsed(String id, String ref);

  // Building directory + generic Pages — same real-backend-in-Rest-mode/
  // local-in-Mock-mode split as Clubs/Sports/Services.
  Future<List<DirectoryEntry>> getDirectoryEntries();
  Future<void> upsertDirectoryEntry(DirectoryEntry entry);
  Future<void> deleteDirectoryEntry(String id);
  Future<List<AdminPage>> getPages();
  Future<void> upsertPage(AdminPage page);
  Future<void> deletePage(String id);

  // RBAC — real, shared email->role table in Rest mode.
  Future<List<RoleAssignment>> getRoleAssignments();
  /// Single-email lookup — used at sign-in time to resolve the actually
  /// assigned role, without fetching the whole assignment list.
  Future<UserRole?> roleFor(String? email);
  Future<void> setRoleAssignment(String email, UserRole role, {required String assignedBy});
  Future<void> deleteRoleAssignment(String email);

  // Admin activity log (capped at the most recent 200/500 server- or
  // locally-side) — read-only from the UI; every admin write elsewhere
  // logs to this automatically.
  Future<List<AuditLogEntry>> getAuditLog();
  Future<PageSlice<AuditLogEntry>> getAuditLogPage({int page = 1, int perPage = 20});

  // Content revision history ("Sürüm Geçmişi") for the block editor —
  // keyed the same way locally and remotely (e.g. `event:123`).
  Future<List<ContentRevision>> getRevisions(String contentKey);
  Future<void> recordRevision(String contentKey, List<ContentBlock> snapshot, String editorName);

  // Saved posts / follow / block — real, shared state in Rest mode.
  Future<Set<String>> getSavedPostIds();
  Future<bool> toggleSavedPost(String postId);
  Future<Set<String>> getFollowing();
  Future<Set<String>> getBlocked();
  Future<bool> toggleFollow(String peer);
  Future<bool> toggleBlock(String peer);

  // Chat — REST is the history source of truth; Rest mode also listens
  // on Reverb private channels for new rows (see docs/API_CONTRACT.md).
  Future<List<String>> getChatThreadPeers(List<String> knownPeers);
  Future<List<ChatMessage>> getChatMessages(String peer);
  Future<ChatMessage> sendChatMessage(String peer, String text);

  // Backend-delivered notifications (e.g. a real follow event) — additive
  // to NotificationsScreen's existing feed/activity synthesis, not a
  // replacement (see that screen's own doc comment on why).
  Future<List<InboxNotification>> getInboxNotifications();
  Future<PageSlice<InboxNotification>> getInboxNotificationsPage(
      {int page = 1, int perPage = 20});
  Future<void> markNotificationRead(String id);
  Future<void> markAllNotificationsRead();

  /// Device FCM token. Rest mode POSTs `/push-tokens`; mock is a no-op.
  Future<void> registerPushToken({required String token, required String platform});
  Future<void> unregisterPushToken(String token);

  // Survey/poll — student-facing active list + vote, admin management.
  Future<List<Survey>> getActiveSurveys();
  Future<List<Survey>> getAllSurveys();
  Future<Survey> upsertSurvey({
    String? id,
    required String question,
    String? description,
    DateTime? startsAt,
    DateTime? endsAt,
    String targetAudience,
    bool multipleChoice,
    bool anonymous,
    bool showResults,
    bool active,
    required List<String> options,
  });
  Future<Survey> voteSurvey(String surveyId, List<String> optionIds);
  Future<void> deleteSurvey(String id);

  // Academic year — exactly one active at a time (enforced server-side).
  Future<List<AcademicYear>> getAcademicYears();
  Future<void> upsertAcademicYear(
      {required String id,
      required String label,
      required DateTime startsOn,
      required DateTime endsOn,
      bool isActive = false});
  Future<void> deleteAcademicYear(String id);

  // Event participation-type admin management + "kendi aktiviteni
  // oluştur" student submission / admin approval workflow.
  Future<EventParticipationOption> upsertParticipationType(String eventId,
      {String? id, required String label, int sortOrder = 0});
  Future<void> deleteParticipationType(String eventId, String typeId);
  Future<List<CampusEvent>> getPendingActivities();
  Future<void> approveActivity(String id);
  Future<void> rejectActivity(String id, {String? reviewNote});
  Future<CampusEvent> createOwnActivity({
    required String title,
    required String placeId,
    String time,
    DateTime? eventDate,
    String category,
    String description,
  });
  Future<List<CampusEvent>> getMyActivities();

  /// Real "boş/dolu" mekân müsaitliği (docs/EKSIKLER.md §4): every active
  /// (non-rejected) event already booked at [placeId] on [date] — lets the
  /// admin event form and the student own-activity form show a real
  /// conflict before submitting, matching the same check the backend
  /// enforces server-side in createOwnActivity()/upsertEvent().
  Future<List<PlaceBooking>> getPlaceAvailability(String placeId, DateTime date);

  // Real attendance roster ("yoklama") for one event — who actually
  // joined, with their chosen participation type, and whether an admin
  // (club president/teacher) has approved their attendance yet.
  Future<List<EventParticipant>> getEventParticipants(String eventId);
  Future<void> approveEventParticipant(String eventId, String joinId);

  // Admin bulk email + email log/retry.
  Future<List<EmailLogEntry>> getEmailLogs();
  Future<PageSlice<EmailLogEntry>> getEmailLogsPage({int page = 1, int perPage = 20});
  Future<String?> retryEmail(String id);
  Future<int> sendBulkEmail(
      {required List<String> recipients, required String subject, required String body});
}

abstract class AuthProvider {
  Future<bool> signIn();
  Future<bool> signInWithCredentials(String identifier, String password);
  Future<bool> unlockWithBiometrics({String? email});
  Future<String?> getAccessToken();

  /// The account the current session belongs to, once signed in. The
  /// admin-editable email→role table (`CampusRepository.roleFor`) is keyed
  /// on it, so every provider has to be able to answer — otherwise the app
  /// can only resolve a role for the provider types it happens to recognise
  /// by name.
  String? get currentEmail;

  /// Ends the session for real. Whatever this provider handed out — a
  /// server-side token, cached credentials — is revoked and cleared here,
  /// so that logging out is more than the UI forgetting.
  Future<void> signOut();
}

abstract class MapProvider {
  Future<void> startRoute({required String destination, bool accessibleOnly});
  Future<void> openTour(String tourUrl);
}

abstract class AnalyticsTracker {
  void track(String event, [Map<String, Object?> properties = const {}]);
}
