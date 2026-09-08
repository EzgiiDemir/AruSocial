import 'dart:convert';
import 'dart:typed_data';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/config/onboarding_config.dart';
import 'package:arucad_campus_prototype/core/config/shuttle_config.dart';
import 'package:arucad_campus_prototype/core/models/academic_year.dart';
import 'package:arucad_campus_prototype/core/models/achievement_career.dart';
import 'package:arucad_campus_prototype/core/models/admin_page.dart';
import 'package:arucad_campus_prototype/core/models/admin_stats.dart';
import 'package:arucad_campus_prototype/core/models/audit_log_entry.dart';
import 'package:arucad_campus_prototype/core/models/campus_directory.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/campus_weather.dart';
import 'package:arucad_campus_prototype/core/models/chat_message.dart';
import 'package:arucad_campus_prototype/core/models/content_block.dart';
import 'package:arucad_campus_prototype/core/models/content_revision.dart';
import 'package:arucad_campus_prototype/core/models/email_log.dart';
import 'package:arucad_campus_prototype/core/models/event_participant.dart';
import 'package:arucad_campus_prototype/core/models/inbox_notification.dart';
import 'package:arucad_campus_prototype/core/models/media_item.dart';
import 'package:arucad_campus_prototype/core/models/page_slice.dart';
import 'package:arucad_campus_prototype/core/models/role_assignment.dart';
import 'package:arucad_campus_prototype/core/models/staff_application.dart';
import 'package:arucad_campus_prototype/core/models/survey.dart';
import 'package:arucad_campus_prototype/core/models/system_health.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/network/campus_dtos.dart';
import 'package:arucad_campus_prototype/core/network/media_url.dart';
import 'package:arucad_campus_prototype/core/offline/offline_cache_store.dart';
import 'package:arucad_campus_prototype/core/services/ask_arucad_store.dart';

import 'content_moderation.dart';
import 'contracts.dart';
import 'directions_result.dart';
import 'site_settings_store.dart';

class RestCampusRepository implements CampusRepository {
  RestCampusRepository({required this.client}) {
    MediaUrl.bindApiBase(client.baseUrl);
  }

  String? lastAskConversationId;

  /// Real server-side moderation now backs every content-creation call
  /// (`ModerationService` in the Laravel app) — a modified/malicious
  /// client can't just skip the Dart-side `assertTextAllowed` check
  /// anymore. Both a rejected post (`CONTENT_BLOCKED`) and a banned
  /// account (`ACCOUNT_BANNED`, from crossing the strike threshold) are
  /// surfaced through the same [ContentModerationException] the UI
  /// already knows how to show, so no call site needs new catch logic.
  Future<Map<String, dynamic>> _postModerated(String path,
      {Object? body}) async {
    try {
      return await client.post(path, body: body);
    } on ApiClientException catch (e) {
      if (e.code == 'CONTENT_BLOCKED' ||
          e.code == 'ACCOUNT_BANNED' ||
          e.code == 'SOCIAL_MEDIA_UPLOAD_REQUIRED' ||
          e.code == 'MEDIA_PENDING_REVIEW' ||
          e.code == 'MEDIA_REJECTED' ||
          e.code == 'MEDIA_NOT_OWNED') {
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
  Future<Map<String, dynamic>> _postWithPlaceConflictCheck(String path,
      {Object? body}) async {
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

  /// A social post must never be created with a URL that will 404 because
  /// its media is still private. The server repeats this check authoritatively
  /// for clients that bypass Flutter.
  Future<String> _uploadApprovedSocialMedia(Uint8List bytes,
      {required String fileName}) async {
    final item = await uploadMyMedia(bytes, fileName: fileName);
    if (item.moderationStatus != 'approved') {
      throw ContentModerationException(item.moderationStatus == 'rejected'
          ? 'Bu medya yayın için onaylanmadı.'
          : 'Medya inceleme kuyruğunda. Onaylanmadan sosyal paylaşımda görünmez.');
    }
    final url = item.url;
    if (url == null || url.isEmpty) {
      throw const ContentModerationException(
          'Medya URL’si oluşturulamadı. Lütfen tekrar dene.');
    }
    return url;
  }

  static const _pageSize = 20;

  Future<PageSlice<T>> _getPage<T>(
    String path,
    T Function(Map<String, dynamic>) parse, {
    int page = 1,
    int perPage = _pageSize,
  }) async {
    final response = await client.get(path, query: {
      'page': '$page',
      'perPage': '$perPage',
    });
    return PageSlice.fromEnvelope(response, parse);
  }

  @override
  Future<CampusUser> getMe() async {
    final response = await client.get('/me');
    return CampusUserDto.fromJson(response['data'] as Map<String, dynamic>)
        .toDomain();
  }

  @override
  Future<CampusUser> updateProfileBio({
    String? department,
    String? year,
    String? university,
    List<String>? clubs,
    List<String>? achievements,
    List<String>? projects,
    String? avatarUrl,
  }) async {
    final body = <String, dynamic>{
      if (department != null) 'department': department,
      if (year != null) 'year': year,
      if (university != null) 'university': university,
      if (clubs != null) 'clubs': clubs,
      if (achievements != null) 'achievements': achievements,
      if (projects != null) 'projects': projects,
      if (avatarUrl != null) 'avatarUrl': avatarUrl,
    };
    final response = await client.post('/me/profile', body: body);
    return CampusUserDto.fromJson(response['data'] as Map<String, dynamic>)
        .toDomain();
  }

  @override
  Future<({Set<String> done, DateTime startedAt, bool eligible})>
      getOnboardingProgress() async {
    final response = await client.get('/me/onboarding');
    final data = response['data'] as Map<String, dynamic>;
    final done = (data['done'] as List<dynamic>).cast<String>().toSet();
    final startedAt = DateTime.parse(data['startedAt'] as String);
    final eligible = data['eligible'] as bool? ?? false;
    return (done: done, startedAt: startedAt, eligible: eligible);
  }

  @override
  Future<void> setOnboardingStepDone(String stepId, bool done) async {
    await client.post('/me/onboarding/$stepId', body: {'completed': done});
  }

  @override
  Future<List<OnboardingStep>> getOnboardingSteps() async {
    final response = await client.get('/onboarding-steps');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => OnboardingStep.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<List<OnboardingStep>> getAdminOnboardingSteps() async {
    final response = await client.get('/admin/onboarding-steps');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => OnboardingStep.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<OnboardingStep> upsertOnboardingStep(OnboardingStep step) async {
    final response =
        await client.post('/admin/onboarding-steps', body: step.toJson());
    return OnboardingStep.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<void> deleteOnboardingStep(String id) async {
    await client.post('/admin/onboarding-steps/$id/delete');
  }

  @override
  Future<UserSettings> getUserSettings() async {
    final response = await client.get('/me/settings');
    return UserSettings.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<UserSettings> updateUserSettings({
    String? locationVisibility,
    bool? nearbyDiscoverable,
    bool? checkInVisible,
    bool? personalization,
    bool? isPrivateProfile,
    String? preferredLanguage,
  }) async {
    final body = <String, dynamic>{
      if (locationVisibility != null) 'locationVisibility': locationVisibility,
      if (nearbyDiscoverable != null) 'nearbyDiscoverable': nearbyDiscoverable,
      if (checkInVisible != null) 'checkInVisible': checkInVisible,
      if (personalization != null) 'personalization': personalization,
      if (isPrivateProfile != null) 'isPrivateProfile': isPrivateProfile,
      if (preferredLanguage != null) 'preferredLanguage': preferredLanguage,
    };
    final response = await client.post('/me/settings', body: body);
    return UserSettings.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<List<CampusPlace>> getPlaces() async {
    try {
      final response = await client.get('/places');
      final items = response['data'] as List<dynamic>;
      await OfflineCacheStore.putJson('places', items);
      return items
          .map((item) =>
              CampusPlaceDto.fromJson(item as Map<String, dynamic>).toDomain())
          .toList();
    } on ApiClientException catch (e) {
      if (e.code == 'NETWORK_UNREACHABLE') {
        final cached = await OfflineCacheStore.getList('places');
        if (cached != null) {
          return cached
              .map((item) =>
                  CampusPlaceDto.fromJson(item as Map<String, dynamic>)
                      .toDomain())
              .toList();
        }
      }
      rethrow;
    }
  }

  @override
  Future<void> upsertPlace(CampusPlace place) async {
    await client.post('/admin/places', body: {
      'id': place.id,
      'name': place.name,
      'category': place.category,
      'lat': place.lat,
      'lng': place.lng,
      'description': place.description,
      'distance': place.distance,
      'street': place.street,
      'tourUrl': place.tourUrl,
      'tourTarget': place.tourTarget,
      'accessible': place.accessible,
    });
  }

  @override
  Future<void> deletePlace(String id) async {
    await client.post('/admin/places/$id/delete');
  }

  @override
  Future<List<CampusEvent>> getEvents({
    bool includeUnpublished = false,
    String? academicYearId,
    String? category,
    String? placeId,
  }) async {
    final params = <String>[
      if (includeUnpublished) 'includeUnpublished=true',
      if (academicYearId != null) 'academicYearId=$academicYearId',
      if (category != null) 'category=$category',
      if (placeId != null) 'placeId=$placeId',
    ];
    try {
      final response = await client
          .get(params.isEmpty ? '/events' : '/events?${params.join('&')}');
      final items = response['data'] as List<dynamic>;
      if (params.isEmpty) {
        await OfflineCacheStore.putJson('events', items);
      }
      return items
          .map((item) => CampusEvent.fromJson(item as Map<String, dynamic>))
          .toList();
    } on ApiClientException catch (e) {
      if (e.code == 'NETWORK_UNREACHABLE' && params.isEmpty) {
        final cached = await OfflineCacheStore.getList('events');
        if (cached != null) {
          return cached
              .map((item) => CampusEvent.fromJson(item as Map<String, dynamic>))
              .toList();
        }
      }
      rethrow;
    }
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
  Future<List<Achievement>> getAchievements() async {
    final response = await client.get('/me/achievements');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => Achievement.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<List<FeedPost>> getFeed() async {
    try {
      final response = await client.get('/feed');
      final items = response['data'] as List<dynamic>;
      await OfflineCacheStore.putJson('feed', items);
      return items
          .map((item) => FeedPost.fromJson(item as Map<String, dynamic>))
          .toList();
    } on ApiClientException catch (e) {
      if (e.code == 'NETWORK_UNREACHABLE') {
        final cached = await OfflineCacheStore.getList('feed');
        if (cached != null) {
          return cached
              .map((item) => FeedPost.fromJson(item as Map<String, dynamic>))
              .toList();
        }
      }
      rethrow;
    }
  }

  @override
  Future<PageSlice<FeedPost>> getFeedPage({int page = 1, int perPage = 20}) =>
      _getPage('/feed', FeedPost.fromJson, page: page, perPage: perPage);

  @override
  Future<void> createPost(String text,
      {String? imageUrl,
      Uint8List? imageBytes,
      String? mediaFileName,
      PostVisibility visibility = PostVisibility.everyone,
      PostCategory postType = PostCategory.normal,
      String? courseTag,
      String? locationTag}) async {
    // Real bug fix: a device-picked photo used to be dropped on the floor
    // here — this only ever sent `imageUrl`, so a picked-from-gallery/
    // camera image previewed fine in the compose sheet but never actually
    // reached the created post. Upload it through the same real endpoint
    // the profile photo/gallery flows already use to get a real hosted
    // URL first, then send that.
    final resolvedImageUrl = imageBytes != null
        ? await _uploadApprovedSocialMedia(imageBytes,
            fileName: mediaFileName ?? _uploadName(imageBytes, 'post'))
        : imageUrl;
    await _postModerated('/feed', body: {
      'text': text,
      if (resolvedImageUrl != null) 'imageUrl': resolvedImageUrl,
      'visibility': visibility.apiValue,
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
        authorId: '${map['authorId'] ?? ''}',
        authorName: map['authorName'] as String,
        text: map['text'] as String?,
        imageUrl: MediaUrl.resolve(map['imageUrl'] as String?),
        // Real bug fix: never read despite the backend always storing it —
        // every real story rendered with no background color at all.
        backgroundColorValue: (map['backgroundColorValue'] as num?)?.toInt(),
        style: map['style'] is Map
            ? Map<String, dynamic>.from(map['style'] as Map)
            : null,
        visibility: PostVisibilityApi.fromApi(map['visibility'] as String?),
        createdAt: DateTime.tryParse(map['createdAt'] as String? ?? ''),
        viewedByMe: map['viewedByMe'] as bool? ?? false,
      );
    }).toList();
  }

  @override
  Future<void> addStory({
    String? text,
    Uint8List? imageBytes,
    int? backgroundColorValue,
    Map<String, dynamic>? style,
    PostVisibility visibility = PostVisibility.everyone,
  }) async {
    // Real bug fix: same as createPost — a picked photo used to never
    // leave the device. Upload it first to get a real hosted URL.
    final imageUrl = imageBytes != null
        ? await _uploadApprovedSocialMedia(imageBytes,
            fileName: _uploadName(imageBytes, 'story'))
        : null;
    await _postModerated('/stories', body: {
      if (text != null) 'text': text,
      if (imageUrl != null) 'imageUrl': imageUrl,
      if (backgroundColorValue != null)
        'backgroundColorValue': backgroundColorValue,
      if (style != null) 'style': style,
      'visibility': visibility.apiValue,
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
        department: map['department'] as String?,
        avatarUrl: MediaUrl.resolve(map['avatarUrl'] as String?),
      );
    }).toList();
  }

  @override
  Future<FeedPost> toggleLike(String postId) async {
    final response = await client.post('/feed/$postId/like');
    return FeedPost.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<FeedPost> addComment(String postId, String text) async {
    final response =
        await _postModerated('/feed/$postId/comments', body: {'text': text});
    return FeedPost.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<void> reportPost(String postId, String reason) async {
    await client.post('/feed/$postId/report', body: {'reason': reason});
  }

  @override
  Future<FeedPost> updatePost(String postId,
      {String? text, PostVisibility? visibility}) async {
    final response = await _postModerated('/feed/$postId', body: {
      if (text != null) 'text': text,
      if (visibility != null) 'visibility': visibility.apiValue,
    });
    return FeedPost.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<void> deletePost(String postId) async {
    await client.post('/feed/$postId/delete');
  }

  @override
  Future<FeedPost> pinPost(String postId) async {
    final response = await client.post('/feed/$postId/pin');
    return FeedPost.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<FeedPost> unpinPost(String postId) async {
    final response = await client.post('/feed/$postId/unpin');
    return FeedPost.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<FeedPost> createOfficialPost(String text,
      {String? imageUrl, Uint8List? imageBytes}) async {
    final resolved = imageBytes != null
        ? await _uploadApprovedSocialMedia(imageBytes,
            fileName: _uploadName(imageBytes, 'official'))
        : imageUrl;
    final response = await _postModerated('/admin/feed', body: {
      'text': text,
      if (resolved != null) 'imageUrl': resolved,
    });
    return FeedPost.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<void> deleteStory(String storyId) async {
    await client.post('/stories/$storyId/delete');
  }

  @override
  Future<void> markStoryViewed(String storyId) async {
    await client.post('/stories/$storyId/view');
  }

  @override
  Future<List<StoryViewer>> getStoryViewers(String storyId) async {
    final response = await client.get('/stories/$storyId/viewers');
    return (response['data'] as List<dynamic>)
        .map((e) => StoryViewer.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<List<ActivityItem>> getMyActivity() async {
    final response = await client.get('/me/activity');
    final items = response['data'] as List<dynamic>;
    return items.map((item) {
      final map = item as Map<String, dynamic>;
      return ActivityItem(
        id: map['id'] as String,
        kind: ActivityKind.values.firstWhere((v) => v.name == map['kind'],
            orElse: () => ActivityKind.checkIn),
        title: map['title'] as String,
        subtitle: map['subtitle'] as String,
        meta: map['meta'] as String,
        timestamp: map['createdAt'] is String
            ? DateTime.tryParse(map['createdAt'] as String)
            : null,
        xp: map['xp'] as int? ?? 0,
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
  Future<void> setPlaceCover(String placeId, String url) async {
    await client.post('/places/$placeId/cover', body: {'url': url});
  }

  @override
  Future<WorkshopInfo> getWorkshopInfo(String placeId) async {
    final response = await client.get('/places/$placeId/workshop');
    return WorkshopInfo.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<CampusCollaborationPost> addCollaborationPost(
      String placeId, String text) async {
    final response = await _postModerated('/places/$placeId/workshop/posts',
        body: {'text': text});
    return CampusCollaborationPost.fromJson(
        response['data'] as Map<String, dynamic>);
  }

  @override
  Future<WorkshopEquipmentItem> upsertWorkshopEquipment(String placeId,
      {String? id,
      required String name,
      bool available = true,
      int sortOrder = 0}) async {
    final response = await client.post(
        '/admin/places/$placeId/workshop/equipment',
        body: {
          if (id != null) 'id': id,
          'name': name,
          'available': available,
          'sortOrder': sortOrder,
        });
    return WorkshopEquipmentItem.fromJson(
        response['data'] as Map<String, dynamic>);
  }

  @override
  Future<void> deleteWorkshopEquipment(String placeId, String itemId) async {
    await client
        .post('/admin/places/$placeId/workshop/equipment/$itemId/delete');
  }

  @override
  Future<void> deleteCollaborationPost(String placeId, String postId) async {
    await client.post('/admin/places/$placeId/workshop/posts/$postId/delete');
  }

  @override
  Future<List<ShuttleRoute>> getShuttleRoutes() async {
    final response = await client.get('/shuttle-routes');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => ShuttleRoute.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<CampusWeather?> getWeather() async {
    try {
      final response = await client.get('/weather');
      final data = response['data'] as Map<String, dynamic>?;
      final weather = data?['weather'];
      if (weather is! Map<String, dynamic>) return null;
      return CampusWeather.fromJson(weather);
    } catch (_) {
      // Weather is decoration on top of the map, never a blocker — a
      // provider outage hides the row rather than failing the screen.
      return null;
    }
  }

  @override
  Future<ShuttleRoute> upsertShuttleRoute(ShuttleRoute route) async {
    final response =
        await client.post('/admin/shuttle-routes', body: route.toJson());
    return ShuttleRoute.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<void> deleteShuttleRoute(String id) async {
    await client.post('/admin/shuttle-routes/$id/delete');
  }

  @override
  Future<void> checkImageModeration(Uint8List bytes,
      {String mimeType = 'image/jpeg'}) async {
    await _postModerated('/moderation/check-image', body: {
      'imageBase64': base64Encode(bytes),
      'mimeType': mimeType,
    });
  }

  @override
  Future<void> checkIn(
    String placeId, {
    bool visibleToOthers = true,
    required double latitude,
    required double longitude,
    double? accuracy,
  }) async {
    await client.post('/checkins', body: {
      'placeId': placeId,
      'visibleToOthers': visibleToOthers,
      'latitude': latitude,
      'longitude': longitude,
      if (accuracy != null) 'accuracy': accuracy,
    });
  }

  @override
  Future<EventJoinResult> joinEvent(String eventId,
      {String? participationTypeId}) async {
    final response = await client.post('/events/$eventId/join',
        body: participationTypeId == null
            ? null
            : {'participationTypeId': participationTypeId});
    final status =
        (response['data'] as Map<String, dynamic>)['participationStatus']
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
  Future<String> askGuide(String prompt,
      {List<({bool fromUser, String text})> history = const [],
      String? conversationId}) async {
    final response = await client.post('/ai/query', body: {
      'prompt': prompt,
      if (conversationId != null && conversationId.startsWith('ask-'))
        'conversationId': conversationId,
      'messages': [
        for (final turn in history)
          {'role': turn.fromUser ? 'user' : 'assistant', 'content': turn.text},
        {'role': 'user', 'content': prompt},
      ],
    });
    final data = response['data'];
    if (data is Map<String, dynamic>) {
      lastAskConversationId = data['conversationId'] as String?;
      return data['answer'] as String? ?? '';
    }
    lastAskConversationId = null;
    return '';
  }

  @override
  Future<List<AskArucadConversation>> getAskConversations() async {
    try {
      final response = await client.get('/ask/conversations');
      final items = response['data'] as List<dynamic>;
      await OfflineCacheStore.putJson('ask', items);
      return [
        for (final item in items)
          AskArucadConversation.fromJson(item as Map<String, dynamic>),
      ];
    } on ApiClientException catch (e) {
      if (e.code == 'NETWORK_UNREACHABLE') {
        final cached = await OfflineCacheStore.getList('ask');
        if (cached != null) {
          return [
            for (final item in cached)
              AskArucadConversation.fromJson(item as Map<String, dynamic>),
          ];
        }
      }
      rethrow;
    }
  }

  @override
  Future<AskArucadConversation?> getAskConversation(String id) async {
    try {
      final response = await client.get('/ask/conversations/$id');
      return AskArucadConversation.fromJson(
          response['data'] as Map<String, dynamic>);
    } on ApiClientException catch (e) {
      if (e.statusCode == 404) return null;
      rethrow;
    }
  }

  @override
  Future<void> deleteAskConversation(String id) async {
    await client.post('/ask/conversations/$id/delete');
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
  Future<List<CampusEvent>> getTrainerEvents() async {
    final response = await client.get('/trainer/events');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => CampusEvent.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<CampusEvent> upsertTrainerEvent(CampusEvent event,
      {required bool isNew}) async {
    final response =
        await _postWithPlaceConflictCheck('/trainer/events', body: {
      if (!isNew) 'id': event.id,
      'title': event.title,
      'placeId': event.placeId,
      'eventDate': event.eventDate?.toIso8601String().split('T').first,
      'time': event.time,
      'category': event.category,
      'description': event.description,
    });
    return CampusEvent.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<void> deleteTrainerEvent(String id) async {
    await client.post('/trainer/events/$id/delete');
  }

  @override
  Future<List<ParticipationApplication>> getTrainerApplications(
      {String? status}) async {
    final response = await client.get('/trainer/applications', query: {
      if (status != null) 'status': status,
    });
    return (response['data'] as List)
        .map(
            (e) => ParticipationApplication.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<ParticipationApplication> approveTrainerApplication(String id,
      {String? reviewNote}) async {
    final response =
        await client.post('/trainer/applications/$id/approve', body: {
      if (reviewNote != null) 'reviewNote': reviewNote,
    });
    return ParticipationApplication.fromJson(
        response['data'] as Map<String, dynamic>);
  }

  @override
  Future<ParticipationApplication> rejectTrainerApplication(String id,
      {required String reviewNote}) async {
    final response =
        await client.post('/trainer/applications/$id/reject', body: {
      'reviewNote': reviewNote,
    });
    return ParticipationApplication.fromJson(
        response['data'] as Map<String, dynamic>);
  }

  @override
  Future<ParticipationApplication> requestTrainerApplicationRevision(String id,
      {required String reviewNote}) async {
    final response =
        await client.post('/trainer/applications/$id/revise', body: {
      'reviewNote': reviewNote,
    });
    return ParticipationApplication.fromJson(
        response['data'] as Map<String, dynamic>);
  }

  @override
  Future<List<StaffProfile>> getTrainerRoster() async {
    final response = await client.get('/trainer/roster');
    return (response['data'] as List)
        .map((e) => StaffProfile.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<List<EventParticipant>> getTrainerEventParticipants(
      String eventId) async {
    final response = await client.get('/trainer/events/$eventId/participants');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => EventParticipant.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<void> approveTrainerEventParticipant(
      String eventId, String joinId) async {
    await client.post('/trainer/events/$eventId/participants/$joinId/approve');
  }

  @override
  Future<List<CampusClub>> getClubs({String? category}) async {
    final path = category == null ? '/clubs' : '/clubs?category=$category';
    final response = await client.get(path);
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => CampusClub.fromJson(item as Map<String, dynamic>))
        .toList();
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
  Future<Set<String>> getJoinedClubIds() async {
    final response = await client.get('/club-memberships');
    final items = response['data'] as List<dynamic>;
    return items.cast<String>().toSet();
  }

  @override
  Future<void> joinClub(String clubId) async {
    await client.post('/clubs/$clubId/join');
  }

  @override
  Future<void> leaveClub(String clubId) async {
    await client.post('/clubs/$clubId/leave');
  }

  @override
  Future<List<CampusSport>> getSports() async {
    final response = await client.get('/sports');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => CampusSport.fromJson(item as Map<String, dynamic>))
        .toList();
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
    return items
        .map((item) => CampusService.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<void> upsertService(CampusService service) async {
    await client.post('/admin/services', body: service.toJson());
  }

  @override
  Future<void> deleteService(String id) async {
    await client.post('/admin/services/$id/delete');
  }

  String _ymd(DateTime d) =>
      '${d.year.toString().padLeft(4, '0')}-${d.month.toString().padLeft(2, '0')}-${d.day.toString().padLeft(2, '0')}';

  @override
  Future<List<CampusFoodVenue>> getFoodVenues() async {
    final response = await client.get('/food-venues');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => CampusFoodVenue.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<void> upsertFoodVenue(CampusFoodVenue venue) async {
    await client.post('/admin/food-venues', body: {
      'id': venue.id,
      'name': venue.name,
      if (venue.hours != null) 'hours': venue.hours,
      if (venue.menuFileUrl != null) 'menuFileUrl': venue.menuFileUrl,
    });
  }

  @override
  Future<void> deleteFoodVenue(String id) async {
    await client.post('/admin/food-venues/$id/delete');
  }

  @override
  Future<void> upsertFoodMenu(String venueId, DailyMenu menu) async {
    await client.post('/admin/food-venues/$venueId/menus', body: {
      'date': _ymd(menu.date),
      'items': menu.items,
      if (menu.price != null) 'price': menu.price,
      if (menu.hours != null) 'hours': menu.hours,
    });
  }

  @override
  Future<void> deleteFoodMenu(String venueId, DateTime date) async {
    await client.post('/admin/food-venues/$venueId/menus/${_ymd(date)}/delete');
  }

  String _absoluteMediaUrl(String url) {
    if (url.startsWith('http://') ||
        url.startsWith('https://') ||
        url.startsWith('data:')) {
      return url;
    }
    final origin = client.baseUrl.replaceFirst(RegExp(r'/api/v1/?$'), '');
    return url.startsWith('/') ? '$origin$url' : '$origin/$url';
  }

  /// Crop/adjust encodes PNG; magic-byte checks require the extension to match.
  String _uploadName(Uint8List bytes, String stem) {
    if (bytes.length >= 4 &&
        bytes[0] == 0x89 &&
        bytes[1] == 0x50 &&
        bytes[2] == 0x4E &&
        bytes[3] == 0x47) {
      return '$stem.png';
    }
    return '$stem.jpg';
  }

  MediaItem _mediaFrom(Map<String, dynamic> json) {
    final item = MediaItem.fromJson(json);
    final url = item.url;
    if (url == null || url.isEmpty) return item;
    return item.copyWith(url: MediaUrl.resolve(_absoluteMediaUrl(url)));
  }

  @override
  Future<List<MediaItem>> getMedia() async {
    final response = await client.get('/media');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => _mediaFrom(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<MediaItem> uploadMedia(Uint8List bytes,
      {required String fileName}) async {
    final response = await client.postMultipart(
      '/media',
      bytes: bytes,
      fileName: fileName,
    );
    return _mediaFrom(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<void> renameMedia(String id, String fileName) async {
    await client.post('/media/$id', body: {'fileName': fileName});
  }

  @override
  Future<void> deleteMedia(String id) async {
    await client.post('/media/$id/delete');
  }

  @override
  Future<void> markMediaUsed(String id, String ref) async {
    final items = await getMedia();
    final item = items.firstWhere((m) => m.id == id);
    if (item.usedIn.contains(ref)) return;
    await client.post('/media/$id', body: {
      'usedIn': [...item.usedIn, ref],
    });
  }

  @override
  Future<PageSlice<MediaItem>> getMyMediaPage(
          {int page = 1, int perPage = 20}) =>
      _getPage('/media/mine', (json) => _mediaFrom(json),
          page: page, perPage: perPage);

  @override
  Future<MediaItem> uploadMyMedia(Uint8List bytes,
      {required String fileName}) async {
    final response = await client.postMultipart(
      '/media/mine',
      bytes: bytes,
      fileName: fileName,
    );
    return _mediaFrom(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<void> deleteMyMedia(String id) async {
    await client.post('/media/mine/$id/delete');
  }

  @override
  Future<PageSlice<CareerOpportunity>> getCareerOpportunitiesPage(
          {int page = 1, int perPage = 20}) =>
      _getPage('/career/opportunities', CareerOpportunity.fromJson,
          page: page, perPage: perPage);

  @override
  Future<CareerProfile> getCareerProfile() async {
    final response = await client.get('/me/career-profile');
    return CareerProfile.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<CareerProfile> updateCareerProfile({
    String? occupation,
    String? headline,
    String? expertise,
    String? cvUrl,
    bool? lookingForInternships,
    bool? lookingForJobs,
  }) async {
    final body = <String, dynamic>{
      if (occupation != null) 'occupation': occupation,
      if (headline != null) 'headline': headline,
      if (expertise != null) 'expertise': expertise,
      if (lookingForInternships != null)
        'lookingForInternships': lookingForInternships,
      if (lookingForJobs != null) 'lookingForJobs': lookingForJobs,
    };
    final response = await client.post('/me/career-profile', body: body);
    return CareerProfile.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<CareerProfile> uploadCareerCv(Uint8List bytes,
      {required String fileName}) async {
    final response = await client.postMultipart(
      '/me/career-profile/cv',
      bytes: bytes,
      fileName: fileName,
    );
    return CareerProfile.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<void> deleteCareerCv() async {
    await client.post('/me/career-profile/cv/delete');
  }

  @override
  Future<List<int>> downloadOwnCareerCv() =>
      client.getBytes('/me/career-profile/cv');

  @override
  Future<CareerOpportunity> getCareerOpportunity(String id) async {
    final response = await client.get('/career/opportunities/$id');
    return CareerOpportunity.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<CareerApplication> applyToCareerOpportunity(
      String opportunityId) async {
    final response =
        await client.post('/career/opportunities/$opportunityId/apply');
    return CareerApplication.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<List<CareerApplication>> getMyCareerApplications() async {
    final response = await client.get('/me/career-applications');
    return (response['data'] as List)
        .map((e) => CareerApplication.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<List<CareerApplication>> getAdminCareerApplications(
      {String? status, String? opportunityId, String? q}) async {
    final response = await client.get('/admin/career/applications', query: {
      if (status != null) 'status': status,
      if (opportunityId != null) 'opportunityId': opportunityId,
      if (q != null && q.isNotEmpty) 'q': q,
    });
    return (response['data'] as List)
        .map((e) => CareerApplication.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<CareerApplication> updateCareerApplication(String id,
      {String? status, String? adminNotes}) async {
    final response = await client.post('/admin/career/applications/$id', body: {
      if (status != null) 'status': status,
      if (adminNotes != null) 'adminNotes': adminNotes,
    });
    return CareerApplication.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<List<int>> downloadCareerApplicationCv(String id) =>
      client.getBytes('/admin/career/applications/$id/cv');

  @override
  Future<PageSlice<CareerOpportunity>> getAdminCareerOpportunitiesPage(
          {int page = 1, int perPage = 20}) =>
      _getPage('/admin/career/opportunities', CareerOpportunity.fromJson,
          page: page, perPage: perPage);

  @override
  Future<PageSlice<ConsultationOffering>> getConsultationsPage(
          {int page = 1, int perPage = 20}) =>
      _getPage('/consultations', ConsultationOffering.fromJson,
          page: page, perPage: perPage);

  @override
  Future<ConsultationOffering> getConsultation(String id) async {
    final response = await client.get('/consultations/$id');
    return ConsultationOffering.fromJson(
        response['data'] as Map<String, dynamic>);
  }

  @override
  Future<ConsultationApplication> applyToConsultation(String id,
      {String? notes}) async {
    final response = await client.post('/consultations/$id/apply', body: {
      if (notes != null) 'notes': notes,
    });
    return ConsultationApplication.fromJson(
        response['data'] as Map<String, dynamic>);
  }

  @override
  Future<List<ConsultationApplication>> getMyConsultationApplications() async {
    final response = await client.get('/me/consultation-applications');
    return (response['data'] as List)
        .map((e) => ConsultationApplication.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<PageSlice<ConsultationOffering>> getAdminConsultationsPage(
          {int page = 1, int perPage = 20}) =>
      _getPage('/admin/consultations', ConsultationOffering.fromJson,
          page: page, perPage: perPage);

  @override
  Future<void> upsertConsultation(ConsultationOffering consultation) async {
    await client.post('/admin/consultations', body: consultation.toJson());
  }

  @override
  Future<void> deleteConsultation(String id) async {
    await client.post('/admin/consultations/$id/delete');
  }

  @override
  Future<List<ConsultationApplication>> getAdminConsultationApplications(
      {String? status, String? q}) async {
    final response =
        await client.get('/admin/consultation-applications', query: {
      if (status != null) 'status': status,
      if (q != null && q.isNotEmpty) 'q': q,
    });
    return (response['data'] as List)
        .map((e) => ConsultationApplication.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<ConsultationApplication> updateConsultationApplication(String id,
      {String? status, String? adminNotes}) async {
    final response =
        await client.post('/admin/consultation-applications/$id', body: {
      if (status != null) 'status': status,
      if (adminNotes != null) 'adminNotes': adminNotes,
    });
    return ConsultationApplication.fromJson(
        response['data'] as Map<String, dynamic>);
  }

  @override
  Future<void> upsertCareerOpportunity(CareerOpportunity opportunity) async {
    await client.post('/admin/career/opportunities',
        body: opportunity.toJson());
  }

  @override
  Future<void> deleteCareerOpportunity(String id) async {
    await client.post('/admin/career/opportunities/$id/delete');
  }

  @override
  Future<List<StaffProfile>> getStaff({
    String? q,
    String? faculty,
    String? department,
    String? title,
    bool departmentHeadOnly = false,
  }) async {
    final qp = <String, String>{
      if (q != null && q.isNotEmpty) 'q': q,
      if (faculty != null) 'faculty': faculty,
      if (department != null) 'department': department,
      if (title != null) 'title': title,
      if (departmentHeadOnly) 'departmentHeadOnly': '1',
    };
    final response = await client.get('/staff', query: qp.isEmpty ? null : qp);
    return (response['data'] as List)
        .map((e) => StaffProfile.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<List<StaffProfile>> getAdminStaff(
      {String? q, String? department, bool? active}) async {
    final qp = <String, String>{
      if (q != null && q.isNotEmpty) 'q': q,
      if (department != null) 'department': department,
      if (active != null) 'active': active ? '1' : '0',
    };
    final response =
        await client.get('/admin/staff', query: qp.isEmpty ? null : qp);
    return (response['data'] as List)
        .map((e) => StaffProfile.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<void> upsertStaffProfile(StaffProfile staff) async {
    await client.post('/admin/staff', body: {
      'id': staff.id,
      'name': staff.name,
      'faculty': staff.faculty,
      'department': staff.department,
      'title': staff.title,
      'email': staff.email,
      'isDepartmentHead': staff.isDepartmentHead,
      'active': staff.active,
      'userId': staff.userId != null ? int.tryParse(staff.userId!) : null,
    });
  }

  @override
  Future<void> deleteStaffProfile(String id) async {
    await client.post('/admin/staff/$id/delete');
  }

  @override
  Future<List<ApplicationQuestion>> getApplicationQuestions(
      String targetType, String stage) async {
    final response = await client.get('/application-questions', query: {
      'targetType': targetType,
      'stage': stage,
    });
    return (response['data'] as List)
        .map((e) => ApplicationQuestion.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<List<ParticipationApplication>> getMyApplications() async {
    final response = await client.get('/me/applications');
    return (response['data'] as List)
        .map(
            (e) => ParticipationApplication.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<List<Map<String, dynamic>>> getApplicationHistory(
      String applicationId) async {
    final response =
        await client.get('/me/applications/$applicationId/history');
    return (response['data'] as List).cast<Map<String, dynamic>>();
  }

  @override
  Future<ParticipationApplication> submitApplication({
    required String targetType,
    required String targetId,
    String? responsibleStaffId,
    Map<String, dynamic>? formPayload,
  }) async {
    final response = await client.post('/applications', body: {
      'targetType': targetType,
      'targetId': targetId,
      if (responsibleStaffId != null) 'responsibleStaffId': responsibleStaffId,
      if (formPayload != null) 'formPayload': formPayload,
    });
    return ParticipationApplication.fromJson(
        response['data'] as Map<String, dynamic>);
  }

  @override
  Future<ParticipationApplication> submitApplicationDetail(
    String applicationId, {
    Map<String, dynamic>? formPayload,
  }) async {
    final response =
        await client.post('/me/applications/$applicationId/detail', body: {
      if (formPayload != null) 'formPayload': formPayload,
    });
    return ParticipationApplication.fromJson(
        response['data'] as Map<String, dynamic>);
  }

  @override
  Future<List<ParticipationApplication>> getAdminApplications(
      {String? status, String? targetType}) async {
    final qp = <String, String>{
      if (status != null) 'status': status,
      if (targetType != null) 'targetType': targetType,
    };
    final response =
        await client.get('/admin/applications', query: qp.isEmpty ? null : qp);
    return (response['data'] as List)
        .map(
            (e) => ParticipationApplication.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<ParticipationApplication> approveApplication(String id,
      {String? reviewNote}) async {
    final response =
        await client.post('/admin/applications/$id/approve', body: {
      if (reviewNote != null) 'reviewNote': reviewNote,
    });
    return ParticipationApplication.fromJson(
        response['data'] as Map<String, dynamic>);
  }

  @override
  Future<ParticipationApplication> rejectApplication(String id,
      {String? reviewNote}) async {
    final response = await client.post('/admin/applications/$id/reject', body: {
      if (reviewNote != null) 'reviewNote': reviewNote,
    });
    return ParticipationApplication.fromJson(
        response['data'] as Map<String, dynamic>);
  }

  @override
  Future<ParticipationApplication> requestApplicationRevision(String id,
      {required String reviewNote}) async {
    final response = await client.post('/admin/applications/$id/revise', body: {
      'reviewNote': reviewNote,
    });
    return ParticipationApplication.fromJson(
        response['data'] as Map<String, dynamic>);
  }

  @override
  Future<List<AppointmentBooking>> getMyAppointments() async {
    final response = await client.get('/me/appointments');
    return (response['data'] as List)
        .map((e) => AppointmentBooking.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<List<AppointmentBooking>> getAdminAppointments({
    String? staffProfileId,
    String? status,
    String? q,
    String? department,
  }) async {
    final response = await client.get('/admin/appointments', query: {
      if (staffProfileId != null && staffProfileId.isNotEmpty)
        'staffProfileId': staffProfileId,
      if (status != null && status.isNotEmpty) 'status': status,
      if (q != null && q.isNotEmpty) 'q': q,
      if (department != null && department.isNotEmpty) 'department': department,
    });
    return (response['data'] as List)
        .map((e) => AppointmentBooking.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<List<StaffSlot>> getStaffSlots(String staffProfileId,
      {String? date}) async {
    final response = await client.get('/staff/$staffProfileId/slots',
        query: {if (date != null && date.isNotEmpty) 'date': date});
    return (response['data'] as List)
        .map((e) => StaffSlot.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<AppointmentBooking> bookAppointment({
    required String staffProfileId,
    required String date,
    required String startTime,
    required String endTime,
    required String subject,
    String? notes,
    String? applicationId,
  }) async {
    final response = await client.post('/appointments', body: {
      'staffProfileId': staffProfileId,
      'date': date,
      'startTime': startTime,
      'endTime': endTime,
      'subject': subject,
      if (notes != null && notes.isNotEmpty) 'notes': notes,
      if (applicationId != null) 'applicationId': applicationId,
    });
    return AppointmentBooking.fromJson(
        response['data'] as Map<String, dynamic>);
  }

  @override
  Future<void> cancelAppointment(String id) async {
    await client.post('/appointments/$id/cancel');
  }

  @override
  Future<AppointmentBooking> getAppointment(String id) async {
    final response = await client.get('/appointments/$id');
    return AppointmentBooking.fromJson(
        response['data'] as Map<String, dynamic>);
  }

  @override
  Future<AppointmentBooking> updateAdminAppointment(String id,
      {String? status, String? adminNotes, String? staffProfileId}) async {
    final response = await client.post('/admin/appointments/$id', body: {
      if (status != null) 'status': status,
      if (adminNotes != null) 'adminNotes': adminNotes,
      if (staffProfileId != null) 'staffProfileId': staffProfileId,
    });
    return AppointmentBooking.fromJson(
        response['data'] as Map<String, dynamic>);
  }

  @override
  Future<List<Achievement>> getAdminAchievements() async {
    final response = await client.get('/admin/achievements');
    return (response['data'] as List)
        .map((e) => Achievement.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<void> upsertAchievementDefinition(Achievement definition) async {
    await client.post('/admin/achievements', body: {
      'id': definition.id,
      'title': definition.title,
      'subtitle': definition.subtitle,
      'triggerKind': definition.triggerKind,
      'threshold': definition.threshold,
      'active': !definition.unlocked,
    });
  }

  @override
  Future<void> deleteAchievementDefinition(String id) async {
    await client.post('/admin/achievements/$id/delete');
  }

  @override
  Future<List<DirectoryEntry>> getDirectoryEntries() async {
    final response = await client.get('/directory');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => DirectoryEntry.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<List<CampusBuilding>> getDirectoryBuildings() async {
    final response = await client.get('/directory/buildings');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => CampusBuilding.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<List<CampusFloor>> getDirectoryFloors(String building) async {
    final response = await client
        .get('/directory/buildings/${Uri.encodeComponent(building)}/floors');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => CampusFloor.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<List<CampusRoom>> getDirectoryRooms(
      String building, String floor) async {
    final response = await client.get(
        '/directory/buildings/${Uri.encodeComponent(building)}/floors/${Uri.encodeComponent(floor)}/rooms');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => CampusRoom.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<WalkingRoute?> getWalkingRoute({
    required double fromLat,
    required double fromLng,
    required double toLat,
    required double toLng,
  }) async {
    try {
      final response = await client.post('/routing/directions', body: {
        'fromLat': fromLat,
        'fromLng': fromLng,
        'toLat': toLat,
        'toLng': toLng,
      });
      return WalkingRoute.fromJson(response['data'] as Map<String, dynamic>);
    } on ApiClientException catch (e) {
      if (e.statusCode == 501 ||
          e.statusCode == 502 ||
          e.code == 'ROUTING_NOT_CONFIGURED' ||
          e.code == 'ROUTING_UNAVAILABLE') {
        return null;
      }
      rethrow;
    }
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
    return items
        .map((item) => AdminPage.fromJson(item as Map<String, dynamic>))
        .toList();
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
    return items
        .map((item) => RoleAssignment.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<UserRole?> roleFor(String? email) async {
    if (email == null || email.isEmpty) return null;
    final response = await client.get('/admin/roles/$email');
    final role = (response['data'] as Map<String, dynamic>)['role'] as String?;
    return role == null || role.isEmpty ? null : UserRole.tryParse(role);
  }

  @override
  Future<void> setRoleAssignment(String email, UserRole role,
      {required String assignedBy}) async {
    await client.post('/admin/roles',
        body: {'email': email, 'role': role.name, 'assignedBy': assignedBy});
  }

  @override
  Future<void> deleteRoleAssignment(String email) async {
    await client.post('/admin/roles/$email/delete');
  }

  @override
  Future<List<AuditLogEntry>> getAuditLog() async {
    final response = await client.get('/admin/audit-log');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => AuditLogEntry.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<PageSlice<AuditLogEntry>> getAuditLogPage(
          {int page = 1, int perPage = 20}) =>
      _getPage('/admin/audit-log', AuditLogEntry.fromJson,
          page: page, perPage: perPage);

  @override
  Future<List<ContentRevision>> getRevisions(String contentKey) async {
    final response = await client.get('/content/$contentKey/revisions');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => ContentRevision.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<void> recordRevision(
      String contentKey, List<ContentBlock> snapshot, String editorName) async {
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
    final response =
        await client.post('/saved-posts/toggle', body: {'postId': postId});
    return (response['data'] as Map<String, dynamic>)['saved'] as bool? ??
        false;
  }

  @override
  Future<Set<String>> getFollowing() async {
    final response = await client.get('/social/following');
    return (response['data'] as List<dynamic>).cast<String>().toSet();
  }

  @override
  Future<Set<String>> getFollowers() async {
    final response = await client.get('/social/followers');
    return (response['data'] as List<dynamic>).cast<String>().toSet();
  }

  @override
  Future<List<CampusUser>> getFriends() async {
    final response = await client.get('/social/friends');
    return (response['data'] as List).map((e) {
      final m = e as Map<String, dynamic>;
      return CampusUser(
        id: '${m['id']}',
        name: m['name'] as String,
        role: 'student',
        level: 1,
        xp: 0,
        places: 0,
        events: 0,
        memories: 0,
        interests: const [],
        avatarUrl: MediaUrl.resolve(m['avatarUrl'] as String?),
        department: m['department'] as String?,
      );
    }).toList();
  }

  @override
  Future<CampusUser?> getSocialUser(String id) async {
    final response = await client.get('/social/users/$id');
    final m = response['data'] as Map<String, dynamic>;
    final followerCount = (m['followerCount'] as num?)?.toInt();
    final followingCount = (m['followingCount'] as num?)?.toInt();
    if (m['isLocked'] == true) {
      return CampusUser(
        id: '${m['id']}',
        name: m['name'] as String? ?? 'Gizli profil',
        role: 'student',
        level: 1,
        xp: 0,
        places: 0,
        events: 0,
        memories: 0,
        interests: const [],
        isPrivateProfile: true,
        isLocked: true,
        followerCount: followerCount,
        followingCount: followingCount,
      );
    }
    return CampusUserDto.fromJson(m).toDomain().copyWith(
          followerCount: followerCount,
          followingCount: followingCount,
        );
  }

  @override
  Future<Set<String>> getBlocked() async {
    final response = await client.get('/social/blocked');
    return (response['data'] as List<dynamic>).cast<String>().toSet();
  }

  @override
  Future<bool> toggleFollow(String peer) async {
    final response = await client.post('/social/follow', body: {'peer': peer});
    return (response['data'] as Map<String, dynamic>)['following'] as bool? ??
        false;
  }

  @override
  Future<bool> toggleBlock(String peer) async {
    final response = await client.post('/social/block', body: {'peer': peer});
    return (response['data'] as Map<String, dynamic>)['blocked'] as bool? ??
        false;
  }

  @override
  Future<void> reportUser(String peer, String reason) async {
    await client
        .post('/social/report-user', body: {'peer': peer, 'reason': reason});
  }

  @override
  Future<List<FollowRequestPeer>> getFollowRequests() async {
    final response = await client.get('/social/follow-requests');
    return (response['data'] as List<dynamic>)
        .map((e) => FollowRequestPeer.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<void> acceptFollowRequest(String peer) async {
    await client.post('/social/follow-requests/accept', body: {'peer': peer});
  }

  @override
  Future<void> declineFollowRequest(String peer) async {
    await client.post('/social/follow-requests/decline', body: {'peer': peer});
  }

  @override
  Future<List<ChatThreadPeer>> getChatThreadPeers(
      List<String> knownPeers) async {
    final response = await client.get('/chat/threads');
    return (response['data'] as List<dynamic>)
        .map((e) => ChatThreadPeer.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<List<ChatMessage>> getChatMessages(String peer) async {
    final encoded = Uri.encodeComponent(peer);
    final response = await client.get('/chat/$encoded/messages');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => ChatMessage.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<ChatMessage> sendChatMessage(String peer, String text) async {
    assertTextAllowed(text);
    final encoded = Uri.encodeComponent(peer);
    final response =
        await _postModerated('/chat/$encoded/messages', body: {'text': text});
    return ChatMessage.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<ChatThreadPrefs> getChatPrefs() async {
    final response = await client.get('/chat/prefs');
    return ChatThreadPrefs.fromJsonList(response['data'] as List<dynamic>);
  }

  @override
  Future<ChatThreadPrefState> toggleChatPref(String peer, String field) async {
    final response = await client
        .post('/chat/prefs/toggle', body: {'peer': peer, 'field': field});
    return ChatThreadPrefState.fromJson(
        response['data'] as Map<String, dynamic>);
  }

  @override
  Future<List<ChatGroup>> getChatGroups() async {
    final response = await client.get('/chat/groups');
    return (response['data'] as List<dynamic>)
        .map((e) => ChatGroup.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<ChatGroup> createChatGroup(String name, List<String> members) async {
    final response = await client.post('/chat/groups', body: {
      'name': name,
      'memberNames': members,
    });
    return ChatGroup.fromJson(response['data'] as Map<String, dynamic>);
  }

  ChatMessage _groupMessageFromJson(Map<String, dynamic> json, String myName) {
    final sender = json['senderName'] as String? ?? '';
    return ChatMessage(
      id: json['id'] as String,
      fromMe: sender == myName,
      text: json['text'] as String,
      sentAt: DateTime.tryParse(json['createdAt'] as String? ?? '') ??
          DateTime.now(),
      sender: sender.isEmpty ? null : sender,
      conversationId: json['groupId']?.toString(),
    );
  }

  @override
  Future<List<ChatMessage>> getGroupMessages(String groupId) async {
    final me = await getMe();
    final response = await client.get('/chat/groups/$groupId/messages');
    return (response['data'] as List<dynamic>)
        .map((e) =>
            _groupMessageFromJson(e as Map<String, dynamic>, me.name))
        .toList();
  }

  @override
  Future<ChatMessage> sendGroupMessage(String groupId, String text) async {
    assertTextAllowed(text);
    final me = await getMe();
    final response = await _postModerated('/chat/groups/$groupId/messages',
        body: {'text': text});
    return _groupMessageFromJson(
        response['data'] as Map<String, dynamic>, me.name);
  }

  @override
  Future<void> leaveChatGroup(String id) async {
    await client.post('/chat/groups/$id/leave');
  }

  @override
  Future<ChatGroup> toggleChatGroupPref(String id, String field) async {
    final response = await client
        .post('/chat/groups/$id/prefs/toggle', body: {'field': field});
    return ChatGroup.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<void> reportChatGroup(String id, String reason) async {
    await client.post('/chat/groups/$id/report', body: {'reason': reason});
  }

  @override
  Future<List<InboxNotification>> getInboxNotifications() async {
    final response = await client.get('/notifications');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => InboxNotification.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<PageSlice<InboxNotification>> getInboxNotificationsPage(
          {int page = 1, int perPage = 20}) =>
      _getPage('/notifications', InboxNotification.fromJson,
          page: page, perPage: perPage);

  @override
  Future<void> markNotificationRead(String id) async {
    await client.post('/notifications/$id/read');
  }

  @override
  Future<void> markAllNotificationsRead() async {
    await client.post('/notifications/read-all');
  }

  @override
  Future<void> registerPushToken(
      {required String token, required String platform}) async {
    await client
        .post('/push-tokens', body: {'token': token, 'platform': platform});
  }

  @override
  Future<void> unregisterPushToken(String token) async {
    await client.post('/push-tokens/unregister', body: {'token': token});
  }

  @override
  Future<List<Survey>> getActiveSurveys() async {
    final response = await client.get('/surveys/active');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => Survey.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<List<Survey>> getAllSurveys() async {
    final response = await client.get('/admin/surveys');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => Survey.fromJson(item as Map<String, dynamic>))
        .toList();
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
    final response = await client
        .post('/surveys/$surveyId/vote', body: {'optionIds': optionIds});
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
    return items
        .map((item) => AcademicYear.fromJson(item as Map<String, dynamic>))
        .toList();
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
    final response = await client
        .post('/admin/events/$eventId/participation-types', body: {
      if (id != null) 'id': id,
      'label': label,
      'sortOrder': sortOrder
    });
    return EventParticipationOption.fromJson(
        response['data'] as Map<String, dynamic>);
  }

  @override
  Future<void> deleteParticipationType(String eventId, String typeId) async {
    await client
        .post('/admin/events/$eventId/participation-types/$typeId/delete');
  }

  @override
  Future<List<CampusEvent>> getPendingActivities() async {
    final response = await client.get('/admin/events/pending');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => CampusEvent.fromJson(item as Map<String, dynamic>))
        .toList();
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
    required String responsibleStaffId,
    String time = '',
    DateTime? eventDate,
    String category = 'Öğrenci Etkinliği',
    String description = '',
  }) async {
    final response = await _postWithPlaceConflictCheck('/events/mine', body: {
      'title': title,
      'placeId': placeId,
      'responsibleStaffId': responsibleStaffId,
      'time': time,
      if (eventDate != null)
        'eventDate': eventDate.toIso8601String().split('T').first,
      'category': category,
      'description': description,
    });
    return CampusEvent.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<List<PlaceBooking>> getPlaceAvailability(
      String placeId, DateTime date) async {
    final dateStr = date.toIso8601String().split('T').first;
    final response =
        await client.get('/places/$placeId/availability?date=$dateStr');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => PlaceBooking.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<List<CampusEvent>> getMyActivities() async {
    final response = await client.get('/events/mine');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => CampusEvent.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<List<EventParticipant>> getEventParticipants(String eventId) async {
    final response = await client.get('/admin/events/$eventId/participants');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => EventParticipant.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<void> approveEventParticipant(String eventId, String joinId) async {
    await client.post('/admin/events/$eventId/participants/$joinId/approve');
  }

  @override
  Future<List<EmailLogEntry>> getEmailLogs() async {
    final response = await client.get('/admin/email-logs');
    final items = response['data'] as List<dynamic>;
    return items
        .map((item) => EmailLogEntry.fromJson(item as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<PageSlice<EmailLogEntry>> getEmailLogsPage(
          {int page = 1, int perPage = 20}) =>
      _getPage('/admin/email-logs', EmailLogEntry.fromJson,
          page: page, perPage: perPage);

  @override
  Future<String?> retryEmail(String id) async {
    final response = await client.post('/admin/email-logs/$id/retry');
    return (response['data'] as Map<String, dynamic>)['status'] as String?;
  }

  @override
  Future<int> sendBulkEmail(
      {required List<String> recipients,
      required String subject,
      required String body}) async {
    final response = await client.post('/admin/email/bulk',
        body: {'recipients': recipients, 'subject': subject, 'body': body});
    return (response['data'] as Map<String, dynamic>)['sent'] as int? ?? 0;
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
        reportedAt: DateTime.tryParse(map['reportedAt'] as String? ?? '') ??
            DateTime.now(),
      );
    }).toList();
  }

  @override
  Future<void> resolveReport(String id, ModerationAction action) async {
    await client
        .post('/admin/reports/$id/resolve', body: {'action': action.name});
  }

  @override
  Future<PageSlice<MediaItem>> getModerationQueue(
          {int page = 1, int perPage = 20}) =>
      _getPage('/admin/moderation/queue', (json) => _mediaFrom(json),
          page: page, perPage: perPage);

  @override
  Future<void> resolveModerationQueueItem(String id,
      {required String action}) async {
    await client
        .post('/admin/moderation/queue/$id/resolve', body: {'action': action});
  }

  @override
  Future<PageSlice<FeedPost>> getPendingPosts(
          {int page = 1, int perPage = 20}) =>
      _getPage('/admin/moderation/posts', FeedPost.fromJson,
          page: page, perPage: perPage);

  @override
  Future<void> approvePendingPost(String id) async {
    await client.post('/admin/moderation/posts/$id/approve');
  }

  @override
  Future<void> rejectPendingPost(String id, {String? reviewNote}) async {
    await client.post('/admin/moderation/posts/$id/reject', body: {
      if (reviewNote != null) 'reviewNote': reviewNote,
    });
  }

  @override
  Future<void> snapshotWordpressForms() async {
    await client.post('/admin/wordpress/versions');
  }

  @override
  Future<CampusEvent> draftEventFromPoster(Uint8List bytes,
      {required String fileName}) async {
    final response = await client.postMultipart(
      '/admin/events/draft-from-poster',
      bytes: bytes,
      fileName: fileName,
    );
    final data = response['data'] as Map<String, dynamic>;
    return CampusEvent.fromJson(data['event'] as Map<String, dynamic>);
  }

  @override
  Future<bool> getImageModerationConfigured() async {
    final response = await client.get('/admin/settings/moderation');
    return (response['data'] as Map<String, dynamic>)['configured'] as bool? ??
        false;
  }

  @override
  Future<SystemHealth> getSystemHealth() async {
    final response = await client.get('/admin/system-health');
    return SystemHealth.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<bool> setImageModerationApiKey(String apiKey) async {
    final response = await client
        .post('/admin/settings/moderation', body: {'apiKey': apiKey});
    return (response['data'] as Map<String, dynamic>)['configured'] as bool? ??
        false;
  }

  @override
  Future<SiteSettings> getSiteSettings() async {
    final response = await client.get('/admin/settings/site');
    return SiteSettings.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<SiteSettings> updateSiteSettings({
    EntraSiteConfig? entra,
    String? wordpressSiteUrl,
    String? wordpressApiToken,
  }) async {
    final body = <String, dynamic>{};
    if (entra != null) {
      body['entra'] = {
        'tenantId': entra.tenantId,
        'clientId': entra.clientId,
        'redirectUri': entra.redirectUri,
      };
    }
    if (wordpressSiteUrl != null || wordpressApiToken != null) {
      body['wordpress'] = <String, dynamic>{
        if (wordpressSiteUrl != null) 'siteUrl': wordpressSiteUrl,
        if (wordpressApiToken != null) 'apiToken': wordpressApiToken,
      };
    }
    final response = await client.post('/admin/settings/site', body: body);
    return SiteSettings.fromJson(response['data'] as Map<String, dynamic>);
  }

  @override
  Future<AdminStats> getAdminStats({int days = 14}) async {
    final response = await client.get('/admin/stats?days=$days');
    return AdminStats.fromJson(response['data'] as Map<String, dynamic>);
  }
}
