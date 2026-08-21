import 'dart:convert';
import 'dart:typed_data';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/models/academic_staff.dart';
import 'package:arucad_campus_prototype/core/models/academic_year.dart';
import 'package:arucad_campus_prototype/core/models/admin_page.dart';
import 'package:arucad_campus_prototype/core/models/admin_stats.dart';
import 'package:arucad_campus_prototype/core/models/admin_user.dart';
import 'package:arucad_campus_prototype/core/models/audit_log_entry.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/chat_message.dart';
import 'package:arucad_campus_prototype/core/models/content_block.dart';
import 'package:arucad_campus_prototype/core/models/content_revision.dart';
import 'package:arucad_campus_prototype/core/models/email_log.dart';
import 'package:arucad_campus_prototype/core/models/event_participant.dart';
import 'package:arucad_campus_prototype/core/models/inbox_notification.dart';
import 'package:arucad_campus_prototype/core/models/role_assignment.dart';
import 'package:arucad_campus_prototype/core/models/survey.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/network/campus_dtos.dart';

import 'content_moderation.dart';
import 'contracts.dart';

class RestCampusRepository implements CampusRepository {
  RestCampusRepository({required this.client});

  /// Real server-side moderation now backs every content-creation call
  /// (`ModerationService` in the Laravel app) — a modified/malicious
  /// client can't just skip the Dart-side `assertTextAllowed` check
  /// anymore. Both a rejected post (`CONTENT_BLOCKED`) and a banned
  /// account (`ACCOUNT_BANNED`, from crossing the strike threshold) are
  /// surfaced through the same [ContentModerationException] the UI
  /// already knows how to show, so no call site needs new catch logic.
  Future<Map<String, dynamic>> _postModerated(String path, {Object? body}) async {
    try {
      return await client.post(path, body: body);
    } on ApiClientException catch (e) {
      if (e.code == 'CONTENT_BLOCKED' || e.code == 'ACCOUNT_BANNED') {
        throw ContentModerationException(e.message);
      }
      rethrow;
    }
  }

  /// Real "boş/dolu" mekân çakışması (docs/EKSIKLER.md §4): translates the
  /// backend's real 409 `PLACE_UNAVAILABLE` into [PlaceConflictException]
  /// so both the admin event form and the student own-activity form can
  /// show the real conflict reason without their own ApiClientException
  /// handling.
  Future<Map<String, dynamic>> _postWithPlaceConflictCheck(String path, {Object? body}) async {
    try {
      return await client.post(path, body: body);
    } on ApiClientException catch (e) {
      if (e.code == 'PLACE_UNAVAILABLE') {
        throw PlaceConflictException(e.message);
      }
      rethrow;
    }
  }

  final ApiClient client;

  @override
  Future<AuthSession> startSession({required String email, required String name}) async {
    final response =
        await client.post('/auth/session', body: {'email': email, 'name': name});
    final data = response['data'] as Map<String, dynamic>;
    return AuthSession(
      token: data['token'] as String?,
      email: data['email'] as String,
      name: data['name'] as String,
      role: data['role'] as String? ?? 'student',
    );
  }

  @override
  Future<void> endSession() async {
    try {
      await client.post('/auth/logout');
    } catch (_) {
      // Best-effort — the local session is cleared regardless (see
      // SessionStore.clear() in app.dart's _logout()), so a failed
      // server-side revoke shouldn't block signing out on-device.
    }
  }

  @override
  Future<CampusUser> getMe() async {
    final response = await client.get('/me');
    return CampusUserDto.fromJson(response['data'] as Map<String, dynamic>)
        .toDomain();
  }

  @override
  Future<List<CampusPlace>> getPlaces() async {
    final response = await client.get('/places');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) =>
            CampusPlaceDto.fromJson(item as Map<String, dynamic>).toDomain())
        .toList();
  }

  @override
  Future<List<PlaceDensity>> getPlaceDensity({DensityWindow window = DensityWindow.today}) async {
    final response = await client.get('/places/density?window=${window.apiValue}');
    final items = response['data'] as List<dynamic>;
    return items.map((item) => PlaceDensity.fromJson(item as Map<String, dynamic>)).toList();
  }

  @override
  Future<List<CampusEvent>> getEvents({bool includeUnpublished = false, String? academicYearId}) async {
    final params = <String>[
      if (includeUnpublished) 'includeUnpublished=true',
      if (academicYearId != null) 'academicYearId=$academicYearId',
    ];
    final response =
        await client.get(params.isEmpty ? '/events' : '/events?${params.join('&')}');
    final items = response['data'] as List<dynamic>;
    // CampusEvent.fromJson already parses the full server shape (draft,
    // publishAt/expiresAt, participationTypes, ...) — the old CampusEventDto
    // only carried 7 fields and silently dropped the rest, so it's bypassed
    // here rather than kept in sync with two parsers for the same payload.
    return items
        .map((item) => CampusEvent.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<List<Quest>> getQuests() async {
    final response = await client.get('/me/quests');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) =>
            QuestDto.fromJson(item as Map<String, dynamic>).toDomain())
        .toList();
  }

  @override
  Future<List<FeedPost>> getFeed() async {
    final response = await client.get('/feed');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => FeedPost.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<void> createPost(String text,
      {String? imageUrl,
      Uint8List? imageBytes,
      PostVisibility visibility = PostVisibility.everyone,
      PostCategory postType = PostCategory.normal,
      String? courseTag,
      String? locationTag}) async {
    // A device-picked photo would need a real multipart upload endpoint to
    // get a hosted URL back; this REST path isn't wired to a live backend
    // in this prototype, so only link-based images make the round trip.
    await _postModerated('/feed', body: {
      'text': text,
      if (imageUrl != null) 'imageUrl': imageUrl,
      'visibility': visibility == PostVisibility.onlyMe ? 'onlyMe' : 'everyone',
      'postType': postType.name,
      if (courseTag != null) 'courseTag': courseTag,
      if (locationTag != null) 'locationTag': locationTag,
    });
  }

  @override
  Future<List<CampusStory>> getStories() async {
    final response = await client.get('/stories');
    final items = response['data'] as List<dynamic>;
    return items.map((item) {
      final map = item as Map<String, dynamic>;
      return CampusStory(
        id: map['id'] as String,
        authorId: map['authorId'] as String? ?? '',
        authorName: map['authorName'] as String,
        text: map['text'] as String?,
        visibility: map['visibility'] == 'onlyMe'
            ? PostVisibility.onlyMe
            : PostVisibility.everyone,
        createdAt: DateTime.tryParse(map['createdAt'] as String? ?? ''),
      );
    }).toList();
  }

  @override
  Future<void> addStory({
    String? text,
    Uint8List? imageBytes,
    int? backgroundColorValue,
    PostVisibility visibility = PostVisibility.everyone,
  }) async {
    // As with createPost, a device photo would need real multipart upload
    // support that this prototype's REST path doesn't have.
    await _postModerated('/stories', body: {
      if (text != null) 'text': text,
      if (backgroundColorValue != null) 'backgroundColorValue': backgroundColorValue,
      'visibility': visibility == PostVisibility.onlyMe ? 'onlyMe' : 'everyone',
    });
  }

  @override
  Future<List<LeaderboardEntry>> getLeaderboard() async {
    final response = await client.get('/leaderboard');
    final items = response['data'] as List<dynamic>;
    return items.map((item) {
      final map = item as Map<String, dynamic>;
      return LeaderboardEntry(
        name: map['name'] as String,
        xp: map['xp'] as int,
        isMe: map['isMe'] as bool? ?? false,
      );
    }).toList();
  }

  @override
  Future<void> toggleLike(String postId) async {
    await client.post('/feed/$postId/like');
  }

  @override
  Future<void> addComment(String postId, String text) async {
    await _postModerated('/feed/$postId/comments', body: {'text': text});
  }

  @override
  Future<void> reportPost(String postId, String reason) async {
    await client.post('/feed/$postId/report', body: {'reason': reason});
  }

  @override
  Future<List<ActivityItem>> getMyActivity() async {
    final response = await client.get('/me/activity');
    final items = response['data'] as List<dynamic>;
    return items.map((item) {
      final map = item as Map<String, dynamic>;
      return ActivityItem(
        id: map['id'] as String,
        kind: ActivityKind.values.byName(map['kind'] as String),
        title: map['title'] as String,
        subtitle: map['subtitle'] as String,
        meta: map['meta'] as String,
      );
    }).toList();
  }

  @override
  Future<List<Review>> getReviews(String placeId) async {
    final response = await client.get('/places/$placeId/reviews');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => Review.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<void> addReview(String placeId, int rating, String comment) async {
    await _postModerated('/places/$placeId/reviews',
        body: {'rating': rating, 'comment': comment});
  }

  @override
  Future<void> reportPlace(String placeId, String reason) async {
    await client.post('/places/$placeId/report', body: {'reason': reason});
  }

  @override
  Future<void> checkImageModeration(Uint8List bytes, {String mimeType = 'image/jpeg'}) async {
    await _postModerated('/moderation/check-image', body: {
      'imageBase64': base64Encode(bytes),
      'mimeType': mimeType,
    });
  }

  @override
  Future<void> checkIn(String placeId,
      {required double lat, required double lng, bool visibleToOthers = true}) async {
    try {
      await client.post('/checkins', body: {
        'placeId': placeId,
        'visibleToOthers': visibleToOthers,
        'lat': lat,
        'lng': lng,
      });
    } on ApiClientException catch (e) {
      if (e.code == 'LOCATION_REQUIRED') {
        throw CheckInBlockedException(e.message, locationRequired: true);
      }
      if (e.code == 'TOO_FAR') {
        throw CheckInBlockedException(e.message);
      }
      rethrow;
    }
  }

  @override
  Future<EventJoinResult> joinEvent(String eventId, {String? participationTypeId}) async {
    final response = await client.post('/events/$eventId/join',
        body: participationTypeId == null ? null : {'participationTypeId': participationTypeId});
    final status = (response['data'] as Map<String, dynamic>)['participationStatus']
        as Map<String, dynamic>? ??
        const {};
    return EventJoinResult(
      alreadyJoined: status['alreadyJoined'] as bool? ?? false,
      clubEmailSent: status['clubEmailSent'] as bool? ?? false,
      formEmailSent: status['formEmailSent'] as bool? ?? false,
      formSubmitted: status['formSubmitted'] as bool? ?? false,
    );
  }

  @override
  Future<EventJoinResult> submitEventJoinForm(String eventId) async {
    await client.post('/events/$eventId/join/form');
    return const EventJoinResult(
      alreadyJoined: true,
      clubEmailSent: false,
      formEmailSent: false,
      formSubmitted: true,
    );
  }

  @override
  Future<String> askGuide(String prompt) async {
    final response = await client.post('/ai/query', body: {'prompt': prompt});
    return response['data']?['answer'] as String? ?? '';
  }

  @override
  Future<void> upsertEvent(CampusEvent event) async {
    await _postWithPlaceConflictCheck('/admin/events', body: event.toJson());
  }

  @override
  Future<void> deleteEvent(String id) async {
    await client.post('/admin/events/$id/delete');
  }

  @override
  Future<List<CampusClub>> getClubs() async {
    final response = await client.get('/clubs');
    final items = response['data'] as List<dynamic>;
    return items.map((item) => CampusClub.fromJson(item as Map<String, dynamic>)).toList();
  }

  @override
  Future<void> upsertClub(CampusClub club) async {
    await client.post('/admin/clubs', body: club.toJson());
  }

  @override
  Future<void> deleteClub(String id) async {
    await client.post('/admin/clubs/$id/delete');
  }

  @override
  Future<List<CampusSport>> getSports() async {
    final response = await client.get('/sports');
    final items = response['data'] as List<dynamic>;
    return items.map((item) => CampusSport.fromJson(item as Map<String, dynamic>)).toList();
  }

  @override
  Future<void> upsertSport(CampusSport sport) async {
    await client.post('/admin/sports', body: sport.toJson());
  }

  @override
  Future<void> deleteSport(String id) async {
    await client.post('/admin/sports/$id/delete');
  }

  @override
  Future<List<CampusService>> getServices() async {
    final response = await client.get('/services');
    final items = response['data'] as List<dynamic>;
    return items.map((item) => CampusService.fromJson(item as Map<String, dynamic>)).toList();
  }

  @override
  Future<void> upsertService(CampusService service) async {
    await client.post('/admin/services', body: service.toJson());
  }

  @override
  Future<void> deleteService(String id) async {
    await client.post('/admin/services/$id/delete');
  }

  @override
  Future<List<CampusFoodVenue>> getFoodVenues() async {
    final response = await client.get('/food-venues');
    final items = response['data'] as List<dynamic>;
    return items.map((item) => CampusFoodVenue.fromJson(item as Map<String, dynamic>)).toList();
  }

  @override
  Future<void> upsertFoodVenue(CampusFoodVenue venue) async {
    await client.post('/admin/food-venues', body: venue.toJson());
  }

  @override
  Future<void> deleteFoodVenue(String id) async {
    await client.post('/admin/food-venues/$id/delete');
  }

  String _dateOnly(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  @override
  Future<void> upsertFoodMenu(String venueId, DailyMenu menu) async {
    await client.post('/admin/food-venues/$venueId/menus', body: {
      'date': _dateOnly(menu.date),
      'items': menu.items,
      if (menu.price != null) 'price': menu.price,
      if (menu.hours != null) 'hours': menu.hours,
    });
  }

  @override
  Future<void> deleteFoodMenu(String venueId, DateTime date) async {
    await client.post('/admin/food-venues/$venueId/menus/${_dateOnly(date)}/delete');
  }

  @override
  Future<List<DirectoryEntry>> getDirectoryEntries() async {
    final response = await client.get('/directory');
    final items = response['data'] as List<dynamic>;
    return items.map((item) => DirectoryEntry.fromJson(item as Map<String, dynamic>)).toList();
  }

  @override
  Future<void> upsertDirectoryEntry(DirectoryEntry entry) async {
    await client.post('/admin/directory', body: entry.toJson());
  }

  @override
  Future<void> deleteDirectoryEntry(String id) async {
    await client.post('/admin/directory/$id/delete');
  }

  @override
  Future<List<AdminPage>> getPages() async {
    final response = await client.get('/pages');
    final items = response['data'] as List<dynamic>;
    return items.map((item) => AdminPage.fromJson(item as Map<String, dynamic>)).toList();
  }

  @override
  Future<void> upsertPage(AdminPage page) async {
    await client.post('/admin/pages', body: page.toJson());
  }

  @override
  Future<void> deletePage(String id) async {
    await client.post('/admin/pages/$id/delete');
  }

  @override
  Future<List<RoleAssignment>> getRoleAssignments() async {
    final response = await client.get('/admin/roles');
    final items = response['data'] as List<dynamic>;
    return items.map((item) => RoleAssignment.fromJson(item as Map<String, dynamic>)).toList();
  }

  @override
  Future<UserRole?> roleFor(String? email) async {
    if (email == null || email.isEmpty) return null;
    final response = await client.get('/admin/roles/$email');
    final role = (response['data'] as Map<String, dynamic>)['role'] as String?;
    return role == null ? null : UserRole.values.byName(role);
  }

  @override
  Future<void> setRoleAssignment(String email, UserRole role,
      {required String assignedBy, List<String> permissions = const []}) async {
    await client.post('/admin/roles',
        body: {'email': email, 'role': role.name, 'assignedBy': assignedBy, 'permissions': permissions});
  }

  @override
  Future<void> deleteRoleAssignment(String email) async {
    await client.post('/admin/roles/$email/delete');
  }

  @override
  Future<List<AdminUser>> getAdminUsers() async {
    final response = await client.get('/admin/users');
    final items = response['data'] as List<dynamic>;
    return items.map((item) => AdminUser.fromJson(item as Map<String, dynamic>)).toList();
  }

  @override
  Future<AdminUser> getAdminUser(String id) async {
    final response = await client.get('/admin/users/$id');
    return AdminUser.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<AdminUser> createAdminUser(
      {required String name,
      required String email,
      required UserRole role,
      List<String> permissions = const []}) async {
    final response = await client.post('/admin/users',
        body: {'name': name, 'email': email, 'role': role.name, 'permissions': permissions});
    return AdminUser.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<AdminUser> updateAdminUserRole(String id,
      {required UserRole role, required List<String> permissions}) async {
    final response = await client.post('/admin/users/$id',
        body: {'role': role.name, 'permissions': permissions});
    return AdminUser.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<AdminUser> setAdminUserActive(String id, bool active) async {
    final response = await client.post('/admin/users/$id', body: {'active': active});
    return AdminUser.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<List<AdminUser>> getBannedUsers() async {
    final response = await client.get('/admin/users/banned');
    final items = response['data'] as List<dynamic>;
    return items.map((item) => AdminUser.fromJson(item as Map<String, dynamic>)).toList();
  }

  @override
  Future<AdminUser> unbanUser(String id) async {
    final response = await client.post('/admin/users/$id/unban');
    return AdminUser.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<List<String>> getAllowedDomains() async {
    final response = await client.get('/admin/settings/auth');
    final domains = (response['data'] as Map<String, dynamic>)['allowedDomains'] as List<dynamic>?;
    return domains?.cast<String>() ?? const [];
  }

  @override
  Future<List<String>> setAllowedDomains(List<String> domains) async {
    final response =
        await client.post('/admin/settings/auth', body: {'allowedDomains': domains});
    return ((response['data'] as Map<String, dynamic>)['allowedDomains'] as List<dynamic>).cast<String>();
  }

  @override
  Future<bool> getEntraClientSecretConfigured() async {
    final response = await client.get('/admin/settings/auth');
    return (response['data'] as Map<String, dynamic>)['entraClientSecretConfigured'] as bool;
  }

  @override
  Future<bool> setEntraClientSecret(String secret) async {
    final response =
        await client.post('/admin/settings/auth', body: {'entraClientSecret': secret});
    return (response['data'] as Map<String, dynamic>)['entraClientSecretConfigured'] as bool;
  }

  @override
  Future<int> getCheckinXpAmount() async {
    final response = await client.get('/admin/settings/xp');
    return (response['data'] as Map<String, dynamic>)['checkinXp'] as int;
  }

  @override
  Future<int> setCheckinXpAmount(int amount) async {
    final response = await client.post('/admin/settings/xp', body: {'checkinXp': amount});
    return (response['data'] as Map<String, dynamic>)['checkinXp'] as int;
  }

  @override
  Future<List<AuditLogEntry>> getAuditLog() async {
    final response = await client.get('/admin/audit-log');
    final items = response['data'] as List<dynamic>;
    return items.map((item) => AuditLogEntry.fromJson(item as Map<String, dynamic>)).toList();
  }

  @override
  Future<List<ContentRevision>> getRevisions(String contentKey) async {
    final response = await client.get('/content/$contentKey/revisions');
    final items = response['data'] as List<dynamic>;
    return items.map((item) => ContentRevision.fromJson(item as Map<String, dynamic>)).toList();
  }

  @override
  Future<void> recordRevision(String contentKey, List<ContentBlock> snapshot, String editorName) async {
    await client.post('/content/$contentKey/revisions',
        body: {'snapshot': blocksToJson(snapshot), 'editorName': editorName});
  }

  @override
  Future<Set<String>> getSavedPostIds() async {
    final response = await client.get('/saved-posts');
    final items = response['data'] as List<dynamic>;
    return items.cast<String>().toSet();
  }

  @override
  Future<bool> toggleSavedPost(String postId) async {
    final response = await client.post('/saved-posts/toggle', body: {'postId': postId});
    return (response['data'] as Map<String, dynamic>)['saved'] as bool? ?? false;
  }

  @override
  Future<Set<String>> getFollowing() async {
    final response = await client.get('/social/following');
    return (response['data'] as List<dynamic>).cast<String>().toSet();
  }

  @override
  Future<Set<String>> getBlocked() async {
    final response = await client.get('/social/blocked');
    return (response['data'] as List<dynamic>).cast<String>().toSet();
  }

  @override
  Future<bool> toggleFollow(String peer) async {
    final response = await client.post('/social/follow', body: {'peer': peer});
    return (response['data'] as Map<String, dynamic>)['following'] as bool? ?? false;
  }

  @override
  Future<bool> toggleBlock(String peer) async {
    final response = await client.post('/social/block', body: {'peer': peer});
    return (response['data'] as Map<String, dynamic>)['blocked'] as bool? ?? false;
  }

  @override
  Future<List<ChatThreadSummary>> getChatThreadPeers(List<String> knownPeers) async {
    final response = await client.get('/chat/threads');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => ChatThreadSummary.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<List<ChatMessage>> getChatMessages(String peer) async {
    final response = await client.get('/chat/$peer/messages');
    final items = response['data'] as List<dynamic>;
    return items.map((item) => ChatMessage.fromJson(item as Map<String, dynamic>)).toList();
  }

  @override
  Future<ChatMessage> sendChatMessage(String peer, String text) async {
    final response = await client.post('/chat/$peer/messages', body: {'text': text});
    return ChatMessage.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<List<InboxNotification>> getInboxNotifications() async {
    final response = await client.get('/notifications');
    final items = response['data'] as List<dynamic>;
    return items.map((item) => InboxNotification.fromJson(item as Map<String, dynamic>)).toList();
  }

  @override
  Future<void> markNotificationRead(String id) async {
    await client.post('/notifications/$id/read');
  }

  @override
  Future<void> markAllNotificationsRead() async {
    await client.post('/notifications/read-all');
  }

  @override
  Future<List<Survey>> getActiveSurveys() async {
    final response = await client.get('/surveys/active');
    final items = response['data'] as List<dynamic>;
    return items.map((item) => Survey.fromJson(item as Map<String, dynamic>)).toList();
  }

  @override
  Future<List<Survey>> getAllSurveys() async {
    final response = await client.get('/admin/surveys');
    final items = response['data'] as List<dynamic>;
    return items.map((item) => Survey.fromJson(item as Map<String, dynamic>)).toList();
  }

  @override
  Future<Survey> upsertSurvey({
    String? id,
    required String question,
    String? description,
    DateTime? startsAt,
    DateTime? endsAt,
    String targetAudience = 'Tümü',
    bool multipleChoice = false,
    bool anonymous = true,
    bool showResults = true,
    bool active = true,
    required List<String> options,
  }) async {
    final response = await client.post('/admin/surveys', body: {
      if (id != null) 'id': id,
      'question': question,
      if (description != null) 'description': description,
      if (startsAt != null) 'startsAt': startsAt.toIso8601String(),
      if (endsAt != null) 'endsAt': endsAt.toIso8601String(),
      'targetAudience': targetAudience,
      'multipleChoice': multipleChoice,
      'anonymous': anonymous,
      'showResults': showResults,
      'active': active,
      'options': options,
    });
    return Survey.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<Survey> voteSurvey(String surveyId, List<String> optionIds) async {
    final response =
        await client.post('/surveys/$surveyId/vote', body: {'optionIds': optionIds});
    return Survey.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<void> deleteSurvey(String id) async {
    await client.post('/admin/surveys/$id/delete');
  }

  @override
  Future<List<AcademicYear>> getAcademicYears() async {
    final response = await client.get('/academic-years');
    final items = response['data'] as List<dynamic>;
    return items.map((item) => AcademicYear.fromJson(item as Map<String, dynamic>)).toList();
  }

  @override
  Future<void> upsertAcademicYear({
    required String id,
    required String label,
    required DateTime startsOn,
    required DateTime endsOn,
    bool isActive = false,
  }) async {
    await client.post('/admin/academic-years', body: {
      'id': id,
      'label': label,
      'startsOn': startsOn.toIso8601String().split('T').first,
      'endsOn': endsOn.toIso8601String().split('T').first,
      'isActive': isActive,
    });
  }

  @override
  Future<void> deleteAcademicYear(String id) async {
    await client.post('/admin/academic-years/$id/delete');
  }

  @override
  Future<EventParticipationOption> upsertParticipationType(String eventId,
      {String? id, required String label, int sortOrder = 0}) async {
    final response = await client.post('/admin/events/$eventId/participation-types',
        body: {if (id != null) 'id': id, 'label': label, 'sortOrder': sortOrder});
    return EventParticipationOption.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<void> deleteParticipationType(String eventId, String typeId) async {
    await client.post('/admin/events/$eventId/participation-types/$typeId/delete');
  }

  @override
  Future<List<CampusEvent>> getPendingActivities() async {
    final response = await client.get('/admin/events/pending');
    final items = response['data'] as List<dynamic>;
    return items.map((item) => CampusEvent.fromJson(item as Map<String, dynamic>)).toList();
  }

  @override
  Future<void> approveActivity(String id) async {
    await client.post('/admin/events/$id/approve');
  }

  @override
  Future<void> rejectActivity(String id, {String? reviewNote}) async {
    await client.post('/admin/events/$id/reject',
        body: reviewNote == null ? null : {'reviewNote': reviewNote});
  }

  @override
  Future<CampusEvent> createOwnActivity({
    required String title,
    required String placeId,
    String time = '',
    DateTime? eventDate,
    String category = 'Öğrenci Etkinliği',
    String description = '',
  }) async {
    final response = await _postWithPlaceConflictCheck('/events/mine', body: {
      'title': title,
      'placeId': placeId,
      'time': time,
      if (eventDate != null) 'eventDate': eventDate.toIso8601String().split('T').first,
      'category': category,
      'description': description,
    });
    return CampusEvent.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<List<PlaceBooking>> getPlaceAvailability(String placeId, DateTime date) async {
    final dateStr = date.toIso8601String().split('T').first;
    final response = await client.get('/places/$placeId/availability?date=$dateStr');
    final items = response['data'] as List<dynamic>;
    return items.map((item) => PlaceBooking.fromJson(item as Map<String, dynamic>)).toList();
  }

  @override
  Future<List<CampusEvent>> getMyActivities() async {
    final response = await client.get('/events/mine');
    final items = response['data'] as List<dynamic>;
    return items.map((item) => CampusEvent.fromJson(item as Map<String, dynamic>)).toList();
  }

  @override
  Future<List<EventParticipant>> getEventParticipants(String eventId) async {
    final response = await client.get('/admin/events/$eventId/participants');
    final items = response['data'] as List<dynamic>;
    return items.map((item) => EventParticipant.fromJson(item as Map<String, dynamic>)).toList();
  }

  @override
  Future<void> approveEventParticipant(String eventId, String joinId) async {
    await client.post('/admin/events/$eventId/participants/$joinId/approve');
  }

  @override
  Future<List<EmailLogEntry>> getEmailLogs() async {
    final response = await client.get('/admin/email-logs');
    final items = response['data'] as List<dynamic>;
    return items.map((item) => EmailLogEntry.fromJson(item as Map<String, dynamic>)).toList();
  }

  @override
  Future<String?> retryEmail(String id) async {
    final response = await client.post('/admin/email-logs/$id/retry');
    return (response['data'] as Map<String, dynamic>)['status'] as String?;
  }

  @override
  Future<int> sendBulkEmail(
      {required List<String> recipients, required String subject, required String body}) async {
    final response = await client.post('/admin/email/bulk',
        body: {'recipients': recipients, 'subject': subject, 'body': body});
    return (response['data'] as Map<String, dynamic>)['sent'] as int? ?? 0;
  }

  @override
  Future<List<AcademicStaffMember>> getAcademicStaff() async {
    final response = await client.get('/admin/academic-staff');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => AcademicStaffMember.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<List<ModerationReport>> getReports() async {
    final response = await client.get('/admin/reports');
    final items = response['data'] as List<dynamic>;
    return items.map((item) {
      final map = item as Map<String, dynamic>;
      return ModerationReport(
        id: map['id'] as String,
        kind: map['kind'] == 'place' ? ReportedKind.place : ReportedKind.post,
        targetId: map['targetId'] as String,
        targetLabel: map['targetLabel'] as String,
        reason: map['reason'] as String,
        reportedAt: DateTime.tryParse(map['reportedAt'] as String? ?? '') ?? DateTime.now(),
      );
    }).toList();
  }

  @override
  Future<void> resolveReport(String id, ModerationAction action) async {
    await client.post('/admin/reports/$id/resolve', body: {'action': action.name});
  }

  @override
  Future<bool> getImageModerationConfigured() async {
    final response = await client.get('/admin/settings/moderation');
    return (response['data'] as Map<String, dynamic>)['configured'] as bool? ?? false;
  }

  @override
  Future<bool> setImageModerationApiKey(String apiKey) async {
    final response =
        await client.post('/admin/settings/moderation', body: {'apiKey': apiKey});
    return (response['data'] as Map<String, dynamic>)['configured'] as bool? ?? false;
  }

  @override
  Future<int> getCheckinRadiusMeters() async {
    final response = await client.get('/admin/settings/checkin-radius');
    return (response['data'] as Map<String, dynamic>)['radiusMeters'] as int? ?? 150;
  }

  @override
  Future<int> setCheckinRadiusMeters(int meters) async {
    final response =
        await client.post('/admin/settings/checkin-radius', body: {'radiusMeters': meters});
    return (response['data'] as Map<String, dynamic>)['radiusMeters'] as int? ?? meters;
  }

  @override
  Future<AdminStats> getAdminStats({int days = 14}) async {
    final response = await client.get('/admin/stats?days=$days');
    return AdminStats.fromJson(response['data'] as Map<String, dynamic>);
  }
}
