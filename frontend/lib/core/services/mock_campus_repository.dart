import 'dart:typed_data';

import 'package:collection/collection.dart';

import '../config/campus_life_config.dart';
import '../config/place_catalog.dart';
import '../config/shuttle_config.dart';
import '../models/academic_year.dart';
import '../models/admin_page.dart';
import '../models/admin_stats.dart';
import '../models/campus_models.dart';
import '../models/content_block.dart';
import '../models/content_revision.dart';
import '../models/email_log.dart';
import '../models/event_participant.dart';
import '../models/inbox_notification.dart';
import '../models/survey.dart';
import 'admin_content_store.dart';
import 'admin_page_store.dart';
import 'audit_log_store.dart';
import 'building_directory_store.dart';
import 'chat_store.dart';
import 'content_moderation.dart';
import 'content_revision_store.dart';
import 'contracts.dart';
import 'role_assignment_store.dart';
import 'saved_posts_store.dart';
import 'social_graph_store.dart';

class MockCampusRepository implements CampusRepository {
  final CampusUser _user = const CampusUser(
    id: 'demo-001',
    name: 'Ezgi',
    role: 'Student',
    level: 8,
    xp: 2760,
    places: 48,
    events: 17,
    memories: 86,
    interests: ['Art', 'Photography', 'Cinema'],
    avatarUrl: 'https://api.dicebear.com/7.x/notionists/png?seed=demo-001&size=200',
    department: 'Grafik Tasarım',
    year: '3. Sınıf',
    university: 'ARUCAD',
    clubs: ['Photography Club', 'Cinema Club'],
    achievements: ['ARUCAD Öğrenci Sergisi 2025 — Seçilen İşler'],
    projects: ['Kampüs Yaşamı Fotoğraf Serisi'],
  );

  /// Real record of what the signed-in student has actually done this
  /// session — no invented history. Newest first.
  final List<ActivityItem> _activity = [];

  void _logActivity(ActivityKind kind, String title, String subtitle,
      {int xp = 0}) {
    _activity.insert(
      0,
      ActivityItem(
        id: '${kind.name}-${DateTime.now().microsecondsSinceEpoch}',
        kind: kind,
        title: title,
        subtitle: subtitle,
        meta: 'şimdi',
        xp: xp,
      ),
    );
  }

  int get _lifetimeActivityXp =>
      _activity.fold(0, (sum, a) => sum + a.xp);

  /// [_user.xp] is the seed "before this session" baseline; real totals are
  /// that baseline plus everything genuinely earned since, computed fresh
  /// on every read instead of a static number.
  CampusUser get _computedUser {
    final totalXp = _user.xp + _lifetimeActivityXp;
    return _user.copyWith(xp: totalXp, level: (totalXp ~/ 500) + 1);
  }

  static const List<CampusPlace> _curatedPlaces = [
    CampusPlace(
      id: 'atelier',
      name: 'Atelier',
      category: 'Stüdyo',
      lat: 35.337502,
      lng: 33.321226,
      description: 'Creative production and workshop space for students.',
      distance: '2 min',
      density: 'High activity',
      street: 'Karaca Sokak',
      tourUrl: 'https://360.arucad.edu.tr/tour?campusId=atelier',
      accessible: true,
      photos: 126,
      rating: 4.8,
    ),
    CampusPlace(
      id: 'garden',
      name: 'ARUCAD Garden',
      category: 'Sosyal Alan',
      lat: 35.337125,
      lng: 33.320972,
      description:
          'Open-air campus area for breaks, events and social activity.',
      distance: '4 min',
      density: 'Moderate',
      street: 'Şehit Neriman Sokak',
      tourUrl: 'https://360.arucad.edu.tr/tour?campusId=main',
      accessible: true,
      photos: 84,
      rating: 4.7,
    ),
    CampusPlace(
      id: 'library',
      name: 'Library',
      category: 'Kütüphane',
      lat: 35.337754,
      lng: 33.321358,
      description:
          'Quiet study areas, research support and individual work tables.',
      distance: '6 min',
      density: 'Quiet',
      street: 'Namık Kemal Caddesi',
      tourUrl: 'https://360.arucad.edu.tr/tour?campusId=main',
      accessible: true,
      photos: 61,
      rating: 4.6,
    ),
    CampusPlace(
      id: 'stage',
      name: 'Stage',
      category: 'Sahne',
      lat: 35.337799,
      lng: 33.321082,
      description:
          'Performance venue for screenings, talks and student productions.',
      distance: '5 min',
      density: 'Busy',
      street: 'Cemal Gürsel Caddesi',
      tourUrl: 'https://360.arucad.edu.tr/tour?campusId=bandabuliya',
      accessible: true,
      photos: 73,
      rating: 4.5,
    ),
  ];

  /// The full, real location catalog: the 4 hand-curated places above (rich
  /// descriptions, photo/rating seed data) plus every other verified ARUCAD
  /// location from [pois] that isn't a duplicate of one of them.
  late final List<CampusPlace> _places = [
    ..._curatedPlaces,
    ...placesFromPois(_curatedPlaces),
  ];

  final List<CampusEvent> _events = [
    CampusEvent(
      id: 'ev1',
      title: 'Poster Workshop',
      time: '14:00',
      placeName: 'Atelier',
      category: 'Workshop',
      attendees: 18,
      xp: 80,
      organizer: 'Graphic Design Bölümü',
      description:
          'Sergi ve etkinlik afişleri için pratik bir baskı/tasarım atölyesi. '
          'Kendi projen için taslak getirebilirsin, malzeme atölyede sağlanır.',
    ),
    CampusEvent(
      id: 'ev2',
      title: 'Student Exhibition',
      time: '16:00',
      placeName: 'Gallery',
      category: 'Exhibition',
      attendees: 43,
      xp: 100,
      organizer: 'ARUCAD Galeri',
      description:
          'Dönem sonu öğrenci sergisi — farklı bölümlerden seçilmiş işler bir arada. '
          'Giriş ücretsiz, herkese açık.',
    ),
    CampusEvent(
      id: 'ev3',
      title: 'Film Screening',
      time: '18:00',
      placeName: 'Stage',
      category: 'Cinema',
      attendees: 31,
      xp: 80,
      organizer: 'Cinema Club',
      description:
          'Cinema Club\'ın haftalık gösterimi, ardından kısa bir tartışma. '
          'Film seçimi kulübün sosyal medya hesabından önceden duyurulur.',
    ),
  ];

  final List<FeedPost> _feed = [
    const FeedPost(
        id: 'seed-1',
        name: 'E.',
        text: 'checked in at Atelier',
        meta: '2 min ago · +30 XP',
        likes: 12,
        kind: FeedKind.checkIn),
    const FeedPost(
        id: 'seed-2',
        name: 'A.',
        text: 'shared a memory "Late night studio"',
        meta: '18 min ago',
        likes: 18),
    const FeedPost(
        id: 'seed-3',
        name: 'ARUCAD Events',
        text: 'Film Screening · 18:00 · Stage',
        meta: '31 going',
        likes: 0,
        kind: FeedKind.announcement,
        official: true),
  ];

  final List<CampusStory> _stories = [];
  final List<ModerationReport> _reports = [];

  final List<LeaderboardEntry> _peers = const [
    LeaderboardEntry(name: 'Mert Arslan', xp: 4120, department: 'Mimarlık'),
    LeaderboardEntry(name: 'Deniz Kaya', xp: 3380, department: 'Grafik Tasarım'),
    LeaderboardEntry(name: 'Sude Yılmaz', xp: 2990, department: 'Moda Tasarımı'),
    LeaderboardEntry(name: 'Kaan Tekin', xp: 2410, department: 'Sinema ve Televizyon'),
    LeaderboardEntry(name: 'Elif Şahin', xp: 1870, department: 'İç Mimarlık'),
    LeaderboardEntry(name: 'Ali Rüzgar', xp: 1120, department: 'Güzel Sanatlar'),
    LeaderboardEntry(name: 'Zeynep Aydın', xp: 980, department: 'Grafik Tasarım'),
    LeaderboardEntry(name: 'Emre Doğan', xp: 860, department: 'Mimarlık'),
    LeaderboardEntry(name: 'Ceren Polat', xp: 740, department: 'Moda Tasarımı'),
    LeaderboardEntry(name: 'Burak Çelik', xp: 690, department: 'Sinema ve Televizyon'),
    LeaderboardEntry(name: 'Naz Öztürk', xp: 610, department: 'Güzel Sanatlar'),
    LeaderboardEntry(name: 'Yusuf Aksoy', xp: 540, department: 'İç Mimarlık'),
    LeaderboardEntry(name: 'İrem Kurt', xp: 470, department: 'Grafik Tasarım'),
    LeaderboardEntry(name: 'Barış Yıldız', xp: 390, department: 'Mimarlık'),
    LeaderboardEntry(name: 'Selin Avcı', xp: 310, department: 'Moda Tasarımı'),
    LeaderboardEntry(name: 'Onur Demirtaş', xp: 250, department: 'Sinema ve Televizyon'),
  ];

  final Map<String, List<Review>> _reviews = {
    'atelier': [
      const Review(
          id: 'r-atelier-1',
          placeId: 'atelier',
          author: 'M.',
          rating: 5,
          comment: 'Ekipmanlar güncel, atölye sorumluları çok yardımcı.',
          meta: '3 gün önce'),
      const Review(
          id: 'r-atelier-2',
          placeId: 'atelier',
          author: 'S.',
          rating: 4,
          comment: 'Öğlen saatleri oldukça kalabalık oluyor.',
          meta: '1 hafta önce'),
    ],
    'garden': [
      const Review(
          id: 'r-garden-1',
          placeId: 'garden',
          author: 'D.',
          rating: 5,
          comment: 'Ders arası mola için favori yerim.',
          meta: '2 gün önce'),
    ],
    'library': [
      const Review(
          id: 'r-library-1',
          placeId: 'library',
          author: 'K.',
          rating: 5,
          comment: 'Sessiz, prizler yeterli, wifi hızlı.',
          meta: '5 gün önce'),
    ],
    'stage': [],
  };

  final List<Quest> _quests = const [
    Quest(
        id: 'q1',
        title: 'Creative Week',
        subtitle: 'Visit 3 studios',
        progress: 2,
        target: 3,
        reward: 500),
    Quest(
        id: 'q2',
        title: 'Campus Explorer',
        subtitle: 'Discover 5 new places',
        progress: 3,
        target: 5,
        reward: 350),
    Quest(
        id: 'q3',
        title: 'Event Collector',
        subtitle: 'Attend 4 events',
        progress: 2,
        target: 4,
        reward: 400),
  ];

  @override
  Future<CampusUser> getMe() async => _computedUser;

  @override
  Future<List<CampusPlace>> getPlaces() async => _places;

  @override
  Future<List<CampusEvent>> getEvents({bool includeUnpublished = false, String? academicYearId}) async {
    final base = includeUnpublished ? _events : _events.where((e) => e.isVisibleNow).toList();
    return academicYearId == null
        ? base
        : base.where((e) => e.academicYearId == academicYearId).toList();
  }

  @override
  Future<List<Quest>> getQuests() async => _quests;

  @override
  Future<List<FeedPost>> getFeed() async => List.unmodifiable(_feed);

  static const _checkInXp = 30;

  @override
  Future<void> checkIn(String placeId, {bool visibleToOthers = true}) async {
    CampusPlace? place;
    for (final p in _places) {
      if (p.id == placeId) {
        place = p;
        break;
      }
    }
    final placeName = place?.name ?? placeId;
    // XP is earned either way — "don't show this on social" only skips the
    // feed post, it never affects the reward itself.
    if (visibleToOthers) {
      _feed.insert(
        0,
        FeedPost(
          id: 'checkin-${DateTime.now().millisecondsSinceEpoch}',
          authorId: _user.id,
          name: _user.name,
          text: 'checked in at $placeName',
          meta: 'şimdi · +$_checkInXp XP',
          likes: 0,
          kind: FeedKind.checkIn,
        ),
      );
    }
    _logActivity(ActivityKind.checkIn, 'Check-in: $placeName',
        visibleToOthers ? '+$_checkInXp XP' : '+$_checkInXp XP · gizli',
        xp: _checkInXp);
  }

  @override
  Future<List<Review>> getReviews(String placeId) async =>
      List.unmodifiable(_reviews[placeId] ?? const []);

  @override
  Future<void> addReview(String placeId, int rating, String comment) async {
    assertTextAllowed(comment);
    final list = _reviews.putIfAbsent(placeId, () => []);
    list.insert(
      0,
      Review(
        id: 'review-${DateTime.now().millisecondsSinceEpoch}',
        placeId: placeId,
        author: _user.name,
        rating: rating.clamp(1, 5),
        comment: comment,
        meta: 'şimdi',
      ),
    );
    _logActivity(ActivityKind.review, 'Puanladın: ${_placeName(placeId)}',
        '${'⭐' * rating.clamp(1, 5)} · ${comment.isEmpty ? 'yorum yok' : comment}',
        xp: 15);
  }

  @override
  Future<void> reportPlace(String placeId, String reason) async {
    // No moderation *backend* in this prototype, but there is a real local
    // moderation queue (`_reports`) an admin can act on — see below.
    _logActivity(ActivityKind.report, 'Şikayet ettin: ${_placeName(placeId)}', reason);
    _reports.insert(
      0,
      ModerationReport(
        id: 'report-${DateTime.now().millisecondsSinceEpoch}',
        kind: ReportedKind.place,
        targetId: placeId,
        targetLabel: _placeName(placeId),
        reason: reason,
        reportedAt: DateTime.now(),
      ),
    );
  }

  @override
  Future<void> checkImageModeration(Uint8List bytes, {String mimeType = 'image/jpeg'}) async {
    // Mock mode has no backend to actually scan an image with (and no
    // strikes/ban schema to record against) — honestly a no-op rather than
    // faking a moderation result with nowhere real to run the check.
  }

  @override
  Future<EventJoinResult> joinEvent(String eventId, {String? participationTypeId}) async {
    final event = _eventById(eventId);
    final roster = _eventParticipants.putIfAbsent(eventId, () => []);
    final alreadyJoined = roster.any((p) => p.userId == _user.id);
    if (!alreadyJoined) {
      _logActivity(ActivityKind.eventJoin, 'Katıldın: ${event?.title ?? eventId}',
          '+${event?.xp ?? 0} XP',
          xp: event?.xp ?? 0);
      final label = participationTypeId == null
          ? null
          : event?.participationTypes.where((t) => t.id == participationTypeId).firstOrNull?.label;
      roster.add(EventParticipant(
        id: 'join-${DateTime.now().microsecondsSinceEpoch}',
        userId: _user.id,
        studentName: _user.name,
        participationTypeLabel: label,
        joinedAt: DateTime.now(),
      ));
    }
    // Mock mode has no real email pipeline — honestly report nothing sent
    // rather than faking a status the backend would actually control.
    final mine = roster.firstWhere((p) => p.userId == _user.id);
    return EventJoinResult(
      alreadyJoined: alreadyJoined,
      clubEmailSent: false,
      formEmailSent: false,
      formSubmitted: mine.isFormSubmitted,
      emailSupported: false,
    );
  }

  @override
  Future<EventJoinResult> submitEventJoinForm(String eventId) async {
    final roster = _eventParticipants.putIfAbsent(eventId, () => []);
    final index = roster.indexWhere((p) => p.userId == _user.id);
    if (index != -1 && !roster[index].isFormSubmitted) {
      final p = roster[index];
      roster[index] = EventParticipant(
        id: p.id,
        userId: p.userId,
        studentName: p.studentName,
        studentEmail: p.studentEmail,
        participationTypeLabel: p.participationTypeLabel,
        joinedAt: p.joinedAt,
        formSubmittedAt: DateTime.now(),
      );
      final event = _eventById(eventId);
      _logActivity(ActivityKind.eventJoin,
          'Katılım formunu doldurdun: ${event?.title ?? eventId}', 'Onay bekleniyor');
    }
    return const EventJoinResult(
      alreadyJoined: true,
      clubEmailSent: false,
      formEmailSent: false,
      formSubmitted: true,
      emailSupported: false,
    );
  }

  @override
  Future<List<ActivityItem>> getMyActivity() async =>
      List.unmodifiable(_activity);

  @override
  Future<List<LeaderboardEntry>> getLeaderboard() async {
    final me = LeaderboardEntry(
        name: '${_computedUser.name} (Sen)', xp: _computedUser.xp, isMe: true);
    final all = [..._peers, me]..sort((a, b) => b.xp.compareTo(a.xp));
    return all;
  }

  @override
  Future<void> upsertEvent(CampusEvent event) async {
    if (event.placeId != null) {
      final conflict = _placeConflict(event.placeId!, event.eventDate, event.time,
          excludeEventId: event.id);
      if (conflict != null) {
        throw PlaceConflictException(
            'Bu mekân o tarihte ve saatte dolu: "${conflict.title}".');
      }
    }
    final index = _events.indexWhere((e) => e.id == event.id);
    if (index == -1) {
      _events.add(event);
    } else {
      _events[index] = event;
    }
  }

  @override
  Future<void> deleteEvent(String id) async {
    _events.removeWhere((e) => e.id == id);
  }

  @override
  Future<List<CampusClub>> getClubs() => AdminContentStore.clubs();

  @override
  Future<void> upsertClub(CampusClub club) => AdminContentStore.saveClub(club);

  @override
  Future<void> deleteClub(String id) => AdminContentStore.deleteClub(id);

  @override
  Future<List<CampusSport>> getSports() => AdminContentStore.sports();

  @override
  Future<void> upsertSport(CampusSport sport) => AdminContentStore.saveSport(sport);

  @override
  Future<void> deleteSport(String id) => AdminContentStore.deleteSport(id);

  @override
  Future<List<CampusService>> getServices() => AdminContentStore.services();

  @override
  Future<void> upsertService(CampusService service) => AdminContentStore.saveService(service);

  @override
  Future<void> deleteService(String id) => AdminContentStore.deleteService(id);

  @override
  Future<List<DirectoryEntry>> getDirectoryEntries() => BuildingDirectoryStore.entries();

  @override
  Future<void> upsertDirectoryEntry(DirectoryEntry entry) =>
      BuildingDirectoryStore.saveEntry(entry);

  @override
  Future<void> deleteDirectoryEntry(String id) => BuildingDirectoryStore.deleteEntry(id);

  @override
  Future<List<AdminPage>> getPages() => AdminPageStore.pages();

  @override
  Future<void> upsertPage(AdminPage page) => AdminPageStore.save(page);

  @override
  Future<void> deletePage(String id) => AdminPageStore.delete(id);

  @override
  Future<List<RoleAssignment>> getRoleAssignments() => RoleAssignmentStore.assignments();

  @override
  Future<UserRole?> roleFor(String? email) => RoleAssignmentStore.roleFor(email);

  @override
  Future<void> setRoleAssignment(String email, UserRole role, {required String assignedBy}) =>
      RoleAssignmentStore.setRole(email, role, assignedBy: assignedBy);

  @override
  Future<void> deleteRoleAssignment(String email) => RoleAssignmentStore.removeRole(email);

  @override
  Future<List<AuditLogEntry>> getAuditLog() => AuditLogStore.entries();

  @override
  Future<List<ContentRevision>> getRevisions(String contentKey) =>
      ContentRevisionStore.revisionsFor(contentKey);

  @override
  Future<void> recordRevision(String contentKey, List<ContentBlock> snapshot, String editorName) =>
      ContentRevisionStore.record(contentKey, snapshot, editorName);

  @override
  Future<Set<String>> getSavedPostIds() => SavedPostsStore.savedIds();

  @override
  Future<bool> toggleSavedPost(String postId) => SavedPostsStore.toggle(postId);

  @override
  Future<Set<String>> getFollowing() => SocialGraphStore.following();

  @override
  Future<Set<String>> getBlocked() => SocialGraphStore.blocked();

  @override
  Future<bool> toggleFollow(String peer) => SocialGraphStore.toggleFollow(peer);

  @override
  Future<bool> toggleBlock(String peer) => SocialGraphStore.toggleBlock(peer);

  @override
  Future<List<String>> getChatThreadPeers(List<String> knownPeers) =>
      ChatStore.threadPeers(knownPeers);

  @override
  Future<List<ChatMessage>> getChatMessages(String peer) => ChatStore.messages(peer);

  @override
  Future<ChatMessage> sendChatMessage(String peer, String text) async {
    await ChatStore.send(peer, text);
    return (await ChatStore.messages(peer)).last;
  }

  // Mock mode has no real backend notification pipeline — no other real
  // user exists to generate one (same honest scope as NotificationsScreen
  // itself), so this stays genuinely empty rather than faking entries.
  @override
  Future<List<InboxNotification>> getInboxNotifications() async => const [];

  @override
  Future<void> markNotificationRead(String id) async {}

  @override
  Future<void> markAllNotificationsRead() async {}

  final List<_MockSurvey> _surveys = [
    _MockSurvey(
      id: 'survey-demo-1',
      question: 'Bahar Şenliği hangi gün olsun?',
      description: 'Kulüpler ve öğrenci konseyiyle birlikte planlıyoruz.',
      options: [
        _MockSurveyOption(id: 'opt-1', label: 'Cuma'),
        _MockSurveyOption(id: 'opt-2', label: 'Cumartesi'),
      ],
    ),
  ];

  Survey _surveyToModel(_MockSurvey s, {bool forCurrentUser = false}) {
    final totalVotes = s.responses.values.fold(0, (sum, set) => sum + set.length);
    return Survey(
      id: s.id,
      question: s.question,
      description: s.description,
      startsAt: s.startsAt,
      endsAt: s.endsAt,
      targetAudience: s.targetAudience,
      multipleChoice: s.multipleChoice,
      anonymous: s.anonymous,
      showResults: s.showResults,
      active: s.active,
      totalVotes: totalVotes,
      myOptionIds: forCurrentUser ? (s.responses[_user.id]?.toList() ?? const []) : const [],
      options: [
        for (final o in s.options)
          SurveyOption(
            id: o.id,
            label: o.label,
            votes: s.responses.values.where((set) => set.contains(o.id)).length,
            percentage: totalVotes == 0
                ? 0
                : double.parse((s.responses.values.where((set) => set.contains(o.id)).length /
                            totalVotes *
                            100)
                        .toStringAsFixed(1)),
          ),
      ],
    );
  }

  @override
  Future<List<Survey>> getActiveSurveys() async {
    final now = DateTime.now();
    return _surveys
        .where((s) =>
            s.active &&
            (s.startsAt == null || !s.startsAt!.isAfter(now)) &&
            (s.endsAt == null || !s.endsAt!.isBefore(now)))
        .map((s) => _surveyToModel(s, forCurrentUser: true))
        .toList();
  }

  @override
  Future<List<Survey>> getAllSurveys() async =>
      _surveys.map((s) => _surveyToModel(s)).toList();

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
    final surveyId = id ?? 'survey-${DateTime.now().microsecondsSinceEpoch}';
    final index = _surveys.indexWhere((s) => s.id == surveyId);
    final survey = _MockSurvey(
      id: surveyId,
      question: question,
      description: description,
      startsAt: startsAt,
      endsAt: endsAt,
      targetAudience: targetAudience,
      multipleChoice: multipleChoice,
      anonymous: anonymous,
      showResults: showResults,
      active: active,
      options: [
        for (var i = 0; i < options.length; i++)
          _MockSurveyOption(id: 'opt-$surveyId-$i', label: options[i]),
      ],
      responses: index == -1 ? {} : _surveys[index].responses,
    );
    if (index == -1) {
      _surveys.add(survey);
    } else {
      _surveys[index] = survey;
    }
    return _surveyToModel(survey);
  }

  @override
  Future<Survey> voteSurvey(String surveyId, List<String> optionIds) async {
    final survey = _surveys.firstWhere((s) => s.id == surveyId);
    survey.responses[_user.id] = optionIds.toSet();
    return _surveyToModel(survey, forCurrentUser: true);
  }

  @override
  Future<void> deleteSurvey(String id) async {
    _surveys.removeWhere((s) => s.id == id);
  }

  final List<AcademicYear> _academicYears = [
    AcademicYear(
      id: '2025-2026',
      label: '2025-2026',
      startsOn: DateTime(2025, 9, 1),
      endsOn: DateTime(2026, 6, 30),
      isActive: true,
    ),
  ];

  @override
  Future<List<AcademicYear>> getAcademicYears() async => List.unmodifiable(_academicYears);

  @override
  Future<void> upsertAcademicYear({
    required String id,
    required String label,
    required DateTime startsOn,
    required DateTime endsOn,
    bool isActive = false,
  }) async {
    if (isActive) {
      for (var i = 0; i < _academicYears.length; i++) {
        if (_academicYears[i].id != id && _academicYears[i].isActive) {
          _academicYears[i] = AcademicYear(
              id: _academicYears[i].id,
              label: _academicYears[i].label,
              startsOn: _academicYears[i].startsOn,
              endsOn: _academicYears[i].endsOn,
              isActive: false);
        }
      }
    }
    final index = _academicYears.indexWhere((y) => y.id == id);
    final year =
        AcademicYear(id: id, label: label, startsOn: startsOn, endsOn: endsOn, isActive: isActive);
    if (index == -1) {
      _academicYears.add(year);
    } else {
      _academicYears[index] = year;
    }
  }

  @override
  Future<void> deleteAcademicYear(String id) async {
    _academicYears.removeWhere((y) => y.id == id);
  }

  CampusEvent _reconstructEvent(CampusEvent e, {
    bool? draft,
    String? workflowStatus,
    String? reviewNote,
    List<EventParticipationOption>? participationTypes,
  }) =>
      CampusEvent(
        id: e.id,
        title: e.title,
        time: e.time,
        placeName: e.placeName,
        placeId: e.placeId,
        category: e.category,
        attendees: e.attendees,
        xp: e.xp,
        draft: draft ?? e.draft,
        publishAt: e.publishAt,
        expiresAt: e.expiresAt,
        audience: e.audience,
        organizer: e.organizer,
        organizerEmail: e.organizerEmail,
        description: e.description,
        academicYearId: e.academicYearId,
        workflowStatus: workflowStatus ?? e.workflowStatus,
        reviewNote: reviewNote ?? e.reviewNote,
        createdByUserId: e.createdByUserId,
        body: e.body,
        participationTypes: participationTypes ?? e.participationTypes,
      );

  @override
  Future<EventParticipationOption> upsertParticipationType(String eventId,
      {String? id, required String label, int sortOrder = 0}) async {
    final index = _events.indexWhere((e) => e.id == eventId);
    if (index == -1) throw StateError('Event not found: $eventId');
    final typeId = id ?? 'ptype-${DateTime.now().microsecondsSinceEpoch}';
    final option = EventParticipationOption(id: typeId, label: label);
    final current = _events[index];
    final types = [
      for (final t in current.participationTypes) if (t.id != typeId) t,
      option,
    ];
    _events[index] = _reconstructEvent(current, participationTypes: types);
    return option;
  }

  @override
  Future<void> deleteParticipationType(String eventId, String typeId) async {
    final index = _events.indexWhere((e) => e.id == eventId);
    if (index == -1) return;
    final current = _events[index];
    _events[index] = _reconstructEvent(current,
        participationTypes: current.participationTypes.where((t) => t.id != typeId).toList());
  }

  @override
  Future<List<CampusEvent>> getPendingActivities() async =>
      _events.where((e) => e.workflowStatus == 'pending_review').toList();

  @override
  Future<void> approveActivity(String id) async {
    final index = _events.indexWhere((e) => e.id == id);
    if (index == -1) return;
    _events[index] = _reconstructEvent(_events[index],
        draft: false, workflowStatus: 'published', reviewNote: null);
  }

  @override
  Future<void> rejectActivity(String id, {String? reviewNote}) async {
    final index = _events.indexWhere((e) => e.id == id);
    if (index == -1) return;
    _events[index] = _reconstructEvent(_events[index],
        workflowStatus: 'rejected', reviewNote: reviewNote ?? '');
  }

  /// Real "boş/dolu" mekân çakışması (docs/EKSIKLER.md §4), mirroring the
  /// backend's placeConflict() check: an active (non-rejected) event
  /// already at [placeId] on the same [eventDate]+[time], other than
  /// [excludeEventId] itself when editing in place.
  CampusEvent? _placeConflict(String placeId, DateTime? eventDate, String time,
      {String? excludeEventId}) {
    if (eventDate == null || time.isEmpty) return null;
    return _events.where((e) {
      if (e.id == excludeEventId) return false;
      if (e.placeId != placeId) return false;
      if (e.time != time) return false;
      if (e.eventDate == null) return false;
      if (e.workflowStatus == 'rejected') return false;
      return e.eventDate!.year == eventDate.year &&
          e.eventDate!.month == eventDate.month &&
          e.eventDate!.day == eventDate.day;
    }).firstOrNull;
  }

  @override
  Future<List<PlaceBooking>> getPlaceAvailability(String placeId, DateTime date) async {
    return _events
        .where((e) =>
            e.placeId == placeId &&
            e.eventDate != null &&
            e.eventDate!.year == date.year &&
            e.eventDate!.month == date.month &&
            e.eventDate!.day == date.day &&
            e.workflowStatus != 'rejected')
        .map((e) => PlaceBooking(
            eventId: e.id, title: e.title, time: e.time, workflowStatus: e.workflowStatus))
        .toList();
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
    final place = (await getPlaces()).where((p) => p.id == placeId).firstOrNull;
    if (place == null) {
      throw StateError('placeId must reference a real, admin-defined place.');
    }
    final conflict = _placeConflict(placeId, eventDate, time);
    if (conflict != null) {
      throw PlaceConflictException(
          'Bu mekân o tarihte ve saatte dolu: "${conflict.title}".');
    }
    final activeYear = _academicYears.where((y) => y.isActive).firstOrNull;
    final event = CampusEvent(
      id: 'event-${DateTime.now().microsecondsSinceEpoch}',
      title: title,
      time: time,
      eventDate: eventDate,
      placeName: place.name,
      placeId: placeId,
      category: category,
      attendees: 0,
      xp: 20,
      draft: true,
      audience: 'Tümü',
      organizer: _user.name,
      description: description,
      academicYearId: activeYear?.id,
      workflowStatus: 'pending_review',
      createdByUserId: _user.id,
    );
    _events.add(event);
    _logActivity(ActivityKind.eventJoin, 'Aktivite önerdin: $title', 'İnceleme bekliyor');
    return event;
  }

  @override
  Future<List<CampusEvent>> getMyActivities() async =>
      _events.where((e) => e.createdByUserId == _user.id).toList();

  // Real per-event join records for the admin "yoklama" roster — Mock
  // mode's single-demo-account reality means this only ever holds the one
  // signed-in student, but the same shape/behavior as the real backend:
  // a join adds a real (unapproved) row, approval flips it for real.
  final Map<String, List<EventParticipant>> _eventParticipants = {};

  @override
  Future<List<EventParticipant>> getEventParticipants(String eventId) async =>
      List.unmodifiable(_eventParticipants[eventId] ?? const []);

  @override
  Future<void> approveEventParticipant(String eventId, String joinId) async {
    final list = _eventParticipants[eventId];
    if (list == null) return;
    final index = list.indexWhere((p) => p.id == joinId);
    if (index == -1) return;
    final p = list[index];
    // Mirrors the real backend's FORM_NOT_SUBMITTED gate — approval isn't
    // possible before the student completes the form.
    if (!p.isFormSubmitted) return;
    list[index] = EventParticipant(
      id: p.id,
      userId: p.userId,
      studentName: p.studentName,
      studentEmail: p.studentEmail,
      participationTypeLabel: p.participationTypeLabel,
      joinedAt: p.joinedAt,
      approvedAt: DateTime.now(),
      approvedBy: _user.name,
    );
  }

  final List<EmailLogEntry> _emailLogs = [];

  @override
  Future<List<EmailLogEntry>> getEmailLogs() async =>
      List.unmodifiable(_emailLogs.reversed);

  // Mock mode has no real mail pipeline — nothing to actually retry.
  @override
  Future<String?> retryEmail(String id) async => null;

  @override
  Future<int> sendBulkEmail(
      {required List<String> recipients, required String subject, required String body}) async {
    for (final email in recipients) {
      _emailLogs.add(EmailLogEntry(
        id: 'mock-email-${DateTime.now().microsecondsSinceEpoch}-$email',
        toEmail: email,
        subject: subject,
        template: 'bulk-announcement',
        status: 'skipped',
        error: 'Mock modda gerçek e-posta gönderimi yok.',
        sentAt: DateTime.now(),
      ));
    }
    // Honest zero — Mock mode never actually sends anything real.
    return 0;
  }

  @override
  Future<List<ModerationReport>> getReports() async => List.unmodifiable(_reports);

  @override
  Future<void> resolveReport(String id, ModerationAction action) async {
    final index = _reports.indexWhere((r) => r.id == id);
    if (index == -1) return;
    final report = _reports[index];
    _reports[index] = report.copyWith(action: action, resolvedAt: DateTime.now());
    if (action == ModerationAction.removed && report.kind == ReportedKind.post) {
      _feed.removeWhere((p) => p.id == report.targetId);
    }
  }

  @override
  Future<bool> getImageModerationConfigured() async => false;

  @override
  Future<bool> setImageModerationApiKey(String apiKey) async => false;

  // Real aggregation over Mock mode's own in-memory state (docs/EKSIKLER.md
  // admin istatistik modülü) — same shape `Admin\StatsController` returns,
  // computed from this session's actual mock data rather than faked, so
  // the İstatistikler tab behaves the same in either mode. Place names are
  // recovered from the "Check-in: <place>" activity title the same way
  // `quests_screen.dart`'s `_visitedPlaceNames` already does, since Mock
  // mode has no dedicated per-checkin table like the real backend's.
  @override
  Future<AdminStats> getAdminStats({int days = 14}) async {
    final checkinActivity = _activity.where((a) => a.kind == ActivityKind.checkIn).toList();
    final placeCounts = <String, int>{};
    for (final a in checkinActivity) {
      final name = a.title.replaceFirst('Check-in: ', '');
      placeCounts[name] = (placeCounts[name] ?? 0) + 1;
    }
    final sortedPlaces = placeCounts.entries.toList()
      ..sort((a, b) => b.value.compareTo(a.value));
    final mostChecked = sortedPlaces
        .take(10)
        .map((e) => PlaceCount(placeId: e.key, placeName: e.key, total: e.value))
        .toList();

    final since = DateTime.now().subtract(Duration(days: days - 1));
    final sinceDay = DateTime(since.year, since.month, since.day);
    final byDayMap = <String, int>{};
    for (final a in checkinActivity) {
      if (a.timestamp.isBefore(sinceDay)) continue;
      final key = '${a.timestamp.year.toString().padLeft(4, '0')}-'
          '${a.timestamp.month.toString().padLeft(2, '0')}-'
          '${a.timestamp.day.toString().padLeft(2, '0')}';
      byDayMap[key] = (byDayMap[key] ?? 0) + 1;
    }
    final byDay = (byDayMap.entries.toList()..sort((a, b) => a.key.compareTo(b.key)))
        .map((e) => DailyCount(day: e.key, total: e.value))
        .toList();

    var totalJoins = 0;
    var formsSubmitted = 0;
    var approved = 0;
    final joinsByEvent = <String, int>{};
    for (final entry in _eventParticipants.entries) {
      totalJoins += entry.value.length;
      for (final p in entry.value) {
        if (p.isFormSubmitted) formsSubmitted++;
        if (p.isApproved) approved++;
      }
      if (entry.value.isNotEmpty) joinsByEvent[entry.key] = entry.value.length;
    }
    final mostJoined = (joinsByEvent.entries.toList()..sort((a, b) => b.value.compareTo(a.value)))
        .take(10)
        .map((e) {
      final event = _events.where((ev) => ev.id == e.key).firstOrNull;
      return EventJoinCount(eventId: e.key, title: event?.title ?? e.key, total: e.value);
    }).toList();

    var totalComments = 0;
    for (final p in _feed) {
      totalComments += p.comments.length;
    }
    final reviewsList = _reviews.values.expand((l) => l).toList();
    final avgRating = reviewsList.isEmpty
        ? 0.0
        : reviewsList.map((r) => r.rating).reduce((a, b) => a + b) / reviewsList.length;

    var totalResponses = 0;
    for (final s in _surveys) {
      totalResponses += s.responses.values.fold(0, (sum, set) => sum + set.length);
    }

    final activityKindCounts = <String, int>{};
    for (final a in _activity) {
      final key = a.kind.name;
      activityKindCounts[key] = (activityKindCounts[key] ?? 0) + 1;
    }

    final clubs = await getClubs();
    final sports = await getSports();
    final services = await getServices();
    final directory = await getDirectoryEntries();

    return AdminStats(
      appUsage: const AppUsageStats(
        trackable: false,
        note: 'Uygulama indirme/kurulum sayısı bu ortamda (Mock mod) da ölçülemez — gerçek '
            'backende geçilse bile bu bir mağaza/analytics metriğidir, bu projenin backend\'i '
            'bunu hiçbir zaman ölçemez.',
      ),
      userSummary: UserSummaryStats(
        realAccountCount: 1,
        note: 'Mock modda tek demo hesap var; buradaki sayılar o hesabın bu oturumdaki '
            'bellek-içi etkinliğidir, uygulama kapanınca sıfırlanır.',
        totalXp: _user.xp,
        totalStrikes: 0,
        bannedAccounts: 0,
      ),
      checkins: CheckinStats(
        total: checkinActivity.length,
        visibleToOthers: _feed.where((p) => p.kind == FeedKind.checkIn).length,
        mostCheckedInPlaces: mostChecked,
        byDay: byDay,
      ),
      events: EventStats(
        total: _events.length,
        published: _events.where((e) => e.isVisibleNow).length,
        pendingReview: _events.where((e) => e.workflowStatus == 'pending_review').length,
        totalJoins: totalJoins,
        formsSubmitted: formsSubmitted,
        attendanceApproved: approved,
        mostJoinedEvents: mostJoined,
      ),
      social: SocialStats(
        feedPosts: _feed.length,
        comments: totalComments,
        stories: (await getStories()).length,
        reviews: reviewsList.length,
        averageRating: double.parse(avgRating.toStringAsFixed(2)),
        moderationReportsFiled: _reports.length,
        moderationReportsUnresolved: _reports.where((r) => r.resolvedAt == null).length,
      ),
      surveys: SurveyStats(total: _surveys.length, totalResponses: totalResponses),
      activityByKind:
          activityKindCounts.entries.map((e) => KindCount(kind: e.key, total: e.value)).toList(),
      email: EmailStats(
        sent: _emailLogs.where((l) => l.status == 'sent').length,
        failed: _emailLogs.where((l) => l.status == 'failed').length,
      ),
      catalog: CatalogStats(
        places: _places.length,
        clubs: clubs.length,
        sports: sports.length,
        services: services.length,
        foodVenues: 0,
        directoryEntries: directory.length,
        mediaItems: 0,
      ),
    );
  }

  @override
  Future<List<CampusStory>> getStories() async {
    _stories.removeWhere((s) => s.isExpired);
    return List.unmodifiable(_stories);
  }

  @override
  Future<void> addStory({
    String? text,
    Uint8List? imageBytes,
    int? backgroundColorValue,
    PostVisibility visibility = PostVisibility.everyone,
  }) async {
    if (text != null) assertTextAllowed(text);
    _stories.insert(
      0,
      CampusStory(
        id: 'story-${DateTime.now().millisecondsSinceEpoch}',
        authorId: _user.id,
        authorName: _user.name,
        text: text,
        imageBytes: imageBytes,
        backgroundColorValue: backgroundColorValue,
        visibility: visibility,
      ),
    );
  }

  String _placeName(String placeId) {
    for (final p in _places) {
      if (p.id == placeId) return p.name;
    }
    return placeId;
  }

  CampusEvent? _eventById(String eventId) {
    for (final e in _events) {
      if (e.id == eventId) return e;
    }
    return null;
  }

  FeedPost? _findPost(String postId) {
    for (final p in _feed) {
      if (p.id == postId) return p;
    }
    return null;
  }

  @override
  Future<void> createPost(String text,
      {String? imageUrl,
      Uint8List? imageBytes,
      PostVisibility visibility = PostVisibility.everyone,
      PostCategory postType = PostCategory.normal,
      String? courseTag,
      String? locationTag}) async {
    assertTextAllowed(text);
    _feed.insert(
      0,
      FeedPost(
        id: 'post-${DateTime.now().millisecondsSinceEpoch}',
        authorId: _user.id,
        name: _user.name,
        text: text,
        meta: 'şimdi',
        likes: 0,
        imageUrl: imageUrl,
        imageBytes: imageBytes,
        visibility: visibility,
        postType: postType,
        courseTag: courseTag,
        locationTag: locationTag,
      ),
    );
  }

  @override
  Future<void> toggleLike(String postId) async {
    final index = _feed.indexWhere((p) => p.id == postId);
    if (index == -1) return;
    final post = _feed[index];
    final liked = !post.likedByMe;
    _feed[index] = post.copyWith(
      likedByMe: liked,
      likes: liked ? post.likes + 1 : (post.likes - 1).clamp(0, 1 << 30),
    );
    final activityId = 'like-$postId';
    _activity.removeWhere((a) => a.id == activityId);
    if (liked) {
      // No XP for likes — a one-tap action with no real content or effort
      // behind it is exactly the kind of thing that turns a score into a
      // farmable number instead of a signal. The activity entry is still
      // logged (so "what did I like" shows up in history), it just never
      // contributed to XP.
      _activity.insert(
        0,
        ActivityItem(
          id: activityId,
          kind: ActivityKind.like,
          title: 'Beğendin: ${post.name}',
          subtitle: post.text,
          meta: 'şimdi',
          xp: 0,
        ),
      );
    }
  }

  @override
  Future<void> addComment(String postId, String text) async {
    assertTextAllowed(text);
    final index = _feed.indexWhere((p) => p.id == postId);
    if (index == -1) return;
    final post = _feed[index];
    final comment = PostComment(
      id: 'comment-${DateTime.now().millisecondsSinceEpoch}',
      author: _user.name,
      text: text,
      meta: 'şimdi',
    );
    _feed[index] = post.copyWith(comments: [...post.comments, comment]);
    _logActivity(ActivityKind.comment, 'Yorum yaptın: ${post.name}', text,
        xp: 5);
  }

  @override
  Future<void> reportPost(String postId, String reason) async {
    final post = _findPost(postId);
    _logActivity(
        ActivityKind.report, 'Gönderiyi şikayet ettin: ${post?.name ?? postId}', reason);
    _reports.insert(
      0,
      ModerationReport(
        id: 'report-${DateTime.now().millisecondsSinceEpoch}',
        kind: ReportedKind.post,
        targetId: postId,
        targetLabel: post == null
            ? postId
            : '${post.name}: ${post.text.isEmpty ? '(fotoğraf)' : post.text}',
        reason: reason,
        reportedAt: DateTime.now(),
      ),
    );
  }

  @override
  Future<String> askGuide(String prompt) async {
    final q = prompt.toLowerCase();
    if (q.contains('yoğun') || q.contains('busy')) {
      return 'Şu anda en hareketli alan Atelier görünüyor. Garden da orta yoğunlukta; daha sakin bir yer istersen Library öneririm.';
    }
    if (q.contains('atelier')) {
      return 'Atelier yaklaşık 2 dakika uzaklıkta. Şu anda yüksek aktivite var ve bugün 14:00\'te Poster Workshop bulunuyor.';
    }
    if (q.contains('kaç') || q.contains('saat') || q.contains('ne zaman')) {
      for (final event in _events) {
        if (q.contains(event.placeName.toLowerCase()) ||
            q.contains(event.title.toLowerCase())) {
          return '${event.title}, ${event.placeName} içinde saat ${event.time}\'te başlıyor · ${event.attendees} kişi katılıyor.';
        }
      }
    }
    if (q.contains('etkinlik')) {
      return 'Bugün 14:00 Poster Workshop, 16:00 Student Exhibition ve 18:00 Film Screening görünüyor.';
    }
    if (q.contains('otobüs') ||
        q.contains('servis') ||
        q.contains('shuttle') ||
        q.contains('bus')) {
      final now = DateTime.now();
      for (final route in shuttleRoutes) {
        final mentioned = q.contains(route.name.toLowerCase()) ||
            route.stops.any((stop) => q.contains(stop.toLowerCase()));
        if (mentioned) {
          final next = nextDeparture(route.departures, now);
          return '${route.name}, kampüsten ${next.label}\'te kalkıyor · ${formatCountdown(next.until)}.';
        }
      }
      final soonest = soonestDeparture();
      return 'En yakın servis ${soonest.route.name}: ${formatCountdown(soonest.until)}. Tüm hatları ve durakları haritadaki Servis sekmesinden görebilirsin.';
    }
    if (q.contains('rota') || q.contains('götür')) {
      return '7 dakikalık Explore Route hazırladım: Atelier → Garden → Library. İstersen hızlı rotaya göre de sadeleştirebilirsin.';
    }
    for (final service in await AdminContentStore.services()) {
      final mentioned = q.contains(service.title.toLowerCase()) ||
          q.contains(service.category.toLowerCase()) ||
          service.title
              .toLowerCase()
              .split(RegExp(r'[\s()]+'))
              .where((w) => w.length > 3)
              .any(q.contains);
      if (mentioned) {
        final where = [service.building, service.floor, service.room]
            .whereType<String>()
            .join(', ');
        return '${service.title}: ${service.description} '
            '${where.isNotEmpty ? 'Konum: $where. ' : ''}İletişim: ${service.contact}';
      }
    }
    for (final club in await AdminContentStore.clubs()) {
      if (q.contains(club.name.toLowerCase())) {
        return '${club.name} (${club.category}): ${club.description}';
      }
    }
    for (final sport in await AdminContentStore.sports()) {
      if (q.contains(sport.name.toLowerCase())) {
        return '${sport.name}: ${sport.facility} kullanılıyor. Katılmak için Keşfet → Spor '
            'kısmından "Katıl / İletişime Geç" ile ulaşabilirsin.';
      }
    }
    if (q.contains('menü') || q.contains('menu') || q.contains('yemek') || q.contains('garden')) {
      for (final venue in await AdminContentStore.foodVenues()) {
        final todayMenu = venue.menuForDay(DateTime.now());
        if (todayMenu == null) {
          return '${venue.name} için bugünün menüsü henüz girilmedi. Discover → The Garden '
              'kısmından takvimden başka bir günü kontrol edebilirsin.';
        }
        final items = todayMenu.items.isEmpty ? 'menü detayı girilmedi' : todayMenu.items.join(', ');
        return '${venue.name} bugün: $items'
            '${todayMenu.price != null ? ' · ${todayMenu.price}' : ''}';
      }
    }
    return 'Ask ARUCAD olarak kampüs, yerler, etkinlikler, kulüpler, spor, yemek, hizmetler, rotalar ve görevler konusunda yardımcı olabilirim.';
  }
}

/// In-memory mutable survey state for Mock mode — kept separate from the
/// immutable [Survey]/[SurveyOption] value classes (which are the same
/// read-only shape the REST API returns) so voting has something to
/// actually mutate. [responses] maps userId -> the set of option ids they
/// picked, mirroring the backend's `survey_responses` rows closely enough
/// that [MockCampusRepository]'s vote-counting logic matches the real
/// server's (`SurveyController::toJson()`) exactly.
class _MockSurvey {
  final String id;
  final String question;
  final String? description;
  final DateTime? startsAt;
  final DateTime? endsAt;
  final String targetAudience;
  final bool multipleChoice;
  final bool anonymous;
  final bool showResults;
  final bool active;
  final List<_MockSurveyOption> options;
  final Map<String, Set<String>> responses;

  _MockSurvey({
    required this.id,
    required this.question,
    this.description,
    this.startsAt,
    this.endsAt,
    this.targetAudience = 'Tümü',
    this.multipleChoice = false,
    this.anonymous = true,
    this.showResults = true,
    this.active = true,
    required this.options,
    Map<String, Set<String>>? responses,
  }) : responses = responses ?? {};
}

class _MockSurveyOption {
  final String id;
  final String label;
  const _MockSurveyOption({required this.id, required this.label});
}
