import 'dart:math' as math;
import 'dart:typed_data';

import 'package:collection/collection.dart';

import '../auth/app_settings_store.dart';
import '../config/campus_life_config.dart';
import '../config/campus_sites.dart';
import '../config/onboarding_config.dart';
import '../config/place_catalog.dart';
import '../config/shuttle_config.dart';
import '../models/academic_year.dart';
import '../models/achievement_career.dart';
import '../models/admin_page.dart';
import '../models/admin_stats.dart';
import '../models/campus_directory.dart';
import '../models/campus_models.dart';
import '../models/chat_message.dart';
import '../models/staff_application.dart';
import '../models/content_block.dart';
import '../models/content_revision.dart';
import '../models/email_log.dart';
import '../models/media_item.dart';
import '../models/event_participant.dart';
import '../models/inbox_notification.dart';
import '../models/page_slice.dart';
import '../models/survey.dart';
import '../models/system_health.dart';
import '../network/api_client.dart';
import 'admin_content_store.dart';
import 'ask_arucad_store.dart';
import 'media_library_store.dart';
import 'admin_page_store.dart';
import 'audit_log_store.dart';
import 'building_directory_store.dart';
import 'chat_realtime_service.dart';
import 'chat_store.dart';
import 'content_moderation.dart';
import 'content_revision_store.dart';
import 'contracts.dart';
import 'directions_result.dart';
import 'place_photo_store.dart';
import 'profile_bio_store.dart';
import 'role_assignment_store.dart';
import 'saved_posts_store.dart';
import 'site_settings_store.dart';
import 'social_graph_store.dart';
import 'social_local_prefs.dart';

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

  /// Mock mode's own persisted bio edits — there's no shared backend row
  /// to write to, so this keeps the previous (already real, already
  /// persisted-on-device) `ProfileBioStore` behavior rather than reverting
  /// to always-the-seed. Applied here (once, in the repository) instead of
  /// by the caller, so `getMe()` returns the same "already merged" shape
  /// in both Mock and Rest mode.
  Future<CampusUser> get _userWithBioOverlay async {
    final edits = await ProfileBioStore.load();
    final base = _computedUser;
    final avatar = await AppSettingsStore.avatarUrl();
    var user = edits == null
        ? base
        : base.copyWith(
            department: edits.department,
            year: edits.year,
            university: edits.university,
            clubs: edits.clubs,
            achievements: edits.achievements,
            projects: edits.projects,
          );
    if (avatar != null && avatar.isNotEmpty) {
      user = user.copyWith(avatarUrl: avatar);
    }
    user = user.copyWith(
      isPrivateProfile: await AppSettingsStore.privateProfile(),
    );
    return user;
  }

  static const List<CampusPlace> _curatedPlaces = [
    CampusPlace(
      id: 'carpentry-studio',
      name: 'Carpentry Studio',
      category: 'Atölye',
      lat: 35.337502,
      lng: 33.321226,
      description: 'Marangozluk atölyesi.',
      distance: '2 min',
      density: 'High activity',
      street: 'Şair Nedim Sokak',
      tourUrl: arucad360MainTourUrl,
      accessible: true,
      photos: 126,
      rating: 4.8,
    ),
    CampusPlace(
      id: 'the-garden',
      name: 'The Garden',
      category: 'Sosyal Alan',
      lat: 35.337125,
      lng: 33.320972,
      description: 'Kampüsün açık sosyal alanı.',
      distance: '4 min',
      density: 'busy',
      street: 'Şair Nedim Sokak',
      tourUrl: arucad360MainTourUrl,
      accessible: true,
      photos: 84,
      rating: 4.7,
    ),
    CampusPlace(
      id: 'meditation',
      name: 'Meditation',
      category: 'Kütüphane',
      lat: 35.337754,
      lng: 33.321358,
      description:
          'Kütüphane, dijital kütüphane ve konferans salonu.',
      distance: '6 min',
      density: 'Quiet',
      street: 'Şair Nedim Sokak',
      tourUrl: arucad360MainTourUrl,
      accessible: true,
      photos: 61,
      rating: 4.6,
    ),
    CampusPlace(
      id: 'the-kiss',
      name: 'The Kiss',
      category: 'Galeri',
      lat: 35.337799,
      lng: 33.321082,
      description:
          'Performans stüdyosu, ARUCAD Galerisi ve Sağlık Merkezi.',
      distance: '5 min',
      density: 'moderate',
      street: 'Şair Nedim Sokak',
      tourUrl: arucad360MainTourUrl,
      accessible: true,
      photos: 73,
      rating: 4.5,
    ),
    CampusPlace(
      id: 'art-rooms',
      name: 'Art Rooms',
      category: 'Atölye/Galeri',
      lat: 35.333593,
      lng: 33.330680,
      description: 'Workshop kümesi içinde galeri / atölye alanı.',
      distance: '12 min',
      density: 'quiet',
      street: 'İskenderun Caddesi',
      tourUrl: arucad360MainTourUrl,
      accessible: true,
      photos: 40,
      rating: 4.4,
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
      placeName: 'Carpentry Studio',
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
      placeName: 'The Kiss',
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
      placeName: 'The Kiss',
      category: 'Cinema',
      attendees: 31,
      xp: 80,
      organizer: 'Cinema Club',
      description:
          'Cinema Club\'ın haftalık gösterimi, ardından kısa bir tartışma. '
          'Film seçimi kulübün sosyal medya hesabından önceden duyurulur.',
    ),
    CampusEvent(
      id: 'ev-banda-1',
      title: 'Bandabuliya Açılış Konuşması',
      time: '18:30',
      eventDate: DateTime.now(),
      placeName: 'Nicosia Bandabuliya Campus',
      placeId: 'place-bandabuliya',
      category: 'Bandabuliya',
      attendees: 24,
      xp: 40,
      organizer: 'Bandabuliya',
      description: 'Haftalık Bandabuliya programının açılış konuşması.',
    ),
    CampusEvent(
      id: 'ev-banda-2',
      title: 'Bandabuliya Baskı Atölyesi',
      time: '16:00',
      eventDate: DateTime.now().add(const Duration(days: 2)),
      placeName: 'Nicosia Bandabuliya Campus',
      placeId: 'place-bandabuliya',
      category: 'Bandabuliya',
      attendees: 12,
      xp: 35,
      organizer: 'Bandabuliya',
      description: 'Açık baskı atölyesi — malzeme kampüs tarafından sağlanır.',
    ),
  ];

  final Set<String> _unlockedAchievements = {};

  final List<CareerOpportunity> _careerOpportunities = [
    CareerOpportunity(
      id: 'career-internship-atelier',
      title: 'Atelier Staj Programı',
      kind: 'internship',
      organization: 'ARUCAD Atelier',
      url: 'https://arucad.edu.tr/career',
      deadline: DateTime.now().add(const Duration(days: 60)),
      description: 'Tasarım atölyelerinde yaz stajı.',
    ),
    CareerOpportunity(
      id: 'career-cv-workshop',
      title: 'CV Atölyesi',
      kind: 'event',
      organization: 'Kariyer Ofisi',
      deadline: DateTime.now().add(const Duration(days: 21)),
      description: 'Portfolyo ve CV değerlendirme oturumu.',
    ),
  ];

  CareerProfile _careerProfile = const CareerProfile();
  final List<CareerApplication> _careerApplications = [];
  final List<ConsultationOffering> _consultations = [
    const ConsultationOffering(
      id: 'consult-cv-review',
      title: 'CV Review',
      purpose: 'CV ve portfolyonun kariyer danışmanı ile birlikte gözden geçirilmesi.',
      audience: 'Staj veya iş arayan öğrenciler.',
      content: 'Bire bir dosya incelemesi ve geribildirim.',
      outcomes: 'Daha güçlü bir CV.',
      duration: '45 dk',
      format: 'Yüz yüze / çevrim içi',
      counselorName: 'Kariyer ve Mezun Ofisi',
    ),
  ];
  final List<ConsultationApplication> _consultationApplications = [];

  static const _achievementDefs = <Achievement>[
    Achievement(
      id: 'ach-first-checkin',
      title: 'İlk Adım',
      subtitle: 'İlk check-in\'ini yap',
      triggerKind: 'checkin_count',
      threshold: 1,
      unlocked: false,
    ),
    Achievement(
      id: 'ach-explorer-5',
      title: 'Kampüs Kâşifi',
      subtitle: '5 farklı mekânda check-in',
      triggerKind: 'distinct_checkins',
      threshold: 5,
      unlocked: false,
    ),
    Achievement(
      id: 'ach-first-event',
      title: 'Sahneye Çık',
      subtitle: 'Bir etkinliğe katıl',
      triggerKind: 'event_joins',
      threshold: 1,
      unlocked: false,
    ),
    Achievement(
      id: 'ach-first-club',
      title: 'Kulüp Üyesi',
      subtitle: 'Bir kulübe katıl',
      triggerKind: 'club_joins',
      threshold: 1,
      unlocked: false,
    ),
    Achievement(
      id: 'ach-first-review',
      title: 'Geri Bildirim',
      subtitle: 'Bir mekânı puanla',
      triggerKind: 'reviews',
      threshold: 1,
      unlocked: false,
    ),
  ];
  late final List<Achievement> _mutableAchievementDefs = List.of(_achievementDefs);

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
    const FeedPost(
        id: 'seed-4',
        name: 'Elif Kaya',
        text: 'Titan’da öğrenci işleri kuyruğu uzadı 😅',
        meta: '4 dk önce · +20 XP',
        likes: 27,
        kind: FeedKind.checkIn),
    const FeedPost(
        id: 'seed-5',
        name: 'Mert Arslan',
        text: 'Garden’da kahve molası.',
        meta: '9 dk önce',
        likes: 14),
    const FeedPost(
        id: 'seed-6',
        name: 'Deniz Yılmaz',
        text: 'Atelier’de maket teslimi gecesi — 12 kişi buradayız.',
        meta: '12 dk önce · +30 XP',
        likes: 41,
        kind: FeedKind.checkIn),
    const FeedPost(
        id: 'seed-7',
        name: 'Sude Tekin',
        text: 'Meditation sessiz, çalışmaya uygun.',
        meta: '18 dk önce',
        likes: 9),
    const FeedPost(
        id: 'seed-8',
        name: 'Kaan Şahin',
        text: 'The Kiss galeride yeni işler var.',
        meta: '22 dk önce',
        likes: 33),
    const FeedPost(
        id: 'seed-9',
        name: 'Zeynep Aydın',
        text: 'Basketbol antrenmanı iptal olmadı, salondayız.',
        meta: '31 dk önce',
        likes: 19),
    const FeedPost(
        id: 'seed-10',
        name: 'Emre Doğan',
        text: 'Yemekhanede mercimek bitmiş — ikinci tura geçtik.',
        meta: '44 dk önce',
        likes: 52),
    const FeedPost(
        id: 'seed-11',
        name: 'Ceren Polat',
        text: 'Bahar Şenliği stant kurulumu başladı!',
        meta: '1 sa önce',
        likes: 67,
        kind: FeedKind.announcement),
    const FeedPost(
        id: 'seed-12',
        name: 'Ali Rüzgar',
        text: 'MAC Lab dolu, 20 dk bekledim.',
        meta: '1 sa önce · +15 XP',
        likes: 11,
        kind: FeedKind.checkIn),
    const FeedPost(
        id: 'seed-13',
        name: 'Ece Demir',
        text: 'Kampüs girişinde güneş muhteşem.',
        meta: '2 sa önce',
        likes: 24),
    const FeedPost(
        id: 'seed-14',
        name: 'Berk Özkan',
        text: 'Daniele stüdyoda çekim var, sessiz olun.',
        meta: '2 sa önce',
        likes: 8),
    const FeedPost(
        id: 'seed-15',
        name: 'İrem Çelik',
        text: 'Kütüphanede priz savaşı 🔌',
        meta: '3 sa önce',
        likes: 36),
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
    'carpentry-studio': [
      const Review(
          id: 'r-carpentry-1',
          placeId: 'carpentry-studio',
          author: 'M.',
          rating: 5,
          comment: 'Ekipmanlar güncel, atölye sorumluları çok yardımcı.',
          meta: '3 gün önce'),
      const Review(
          id: 'r-carpentry-2',
          placeId: 'carpentry-studio',
          author: 'S.',
          rating: 4,
          comment: 'Öğlen saatleri oldukça kalabalık oluyor.',
          meta: '1 hafta önce'),
    ],
    'the-garden': [
      const Review(
          id: 'r-garden-1',
          placeId: 'the-garden',
          author: 'D.',
          rating: 5,
          comment: 'Ders arası mola için favori yerim.',
          meta: '2 gün önce'),
    ],
    'meditation': [
      const Review(
          id: 'r-meditation-1',
          placeId: 'meditation',
          author: 'K.',
          rating: 5,
          comment: 'Sessiz, prizler yeterli, wifi hızlı.',
          meta: '5 gün önce'),
    ],
    'the-kiss': [],
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
  Future<CampusUser> getMe() => _userWithBioOverlay;

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
    final current = await _userWithBioOverlay;
    final edits = ProfileBioEdits(
      department: department ?? current.department,
      year: year ?? current.year,
      university: university ?? current.university,
      clubs: clubs ?? current.clubs,
      achievements: achievements ?? current.achievements,
      projects: projects ?? current.projects,
    );
    await ProfileBioStore.save(edits);
    if (avatarUrl != null) {
      await AppSettingsStore.setAvatarUrl(avatarUrl);
    }
    return _userWithBioOverlay;
  }

  // Mock mode keeps the previous on-device checklist (no shared
  // `onboarding_progress` row to write to). Eligible when unfinished and
  // the mock "started" window is still open (≤14 days) — mirrors the
  // server's conservative new-account rule when year is unknown.
  @override
  Future<({Set<String> done, DateTime startedAt, bool eligible})> getOnboardingProgress() async {
    final done = await AppSettingsStore.onboardingDone();
    final startedAt = await AppSettingsStore.onboardingStartedAt();
    final eligible = DateTime.now().difference(startedAt).inDays <= 14;
    return (done: done, startedAt: startedAt, eligible: eligible);
  }

  @override
  Future<void> setOnboardingStepDone(String stepId, bool done) =>
      AppSettingsStore.setOnboardingStepDone(stepId, done);

  // Mock mode has no backend, so the hardcoded `onboardingSteps` const is a
  // legitimate offline seed here (REST mode is the real, admin-editable
  // source of truth — see OnboardingStepController).
  List<OnboardingStep>? _onboardingSteps;

  @override
  Future<List<OnboardingStep>> getOnboardingSteps() async {
    return List.unmodifiable(
        (_onboardingSteps ?? onboardingSteps).where((s) => s.active));
  }

  @override
  Future<List<OnboardingStep>> getAdminOnboardingSteps() async {
    return List.unmodifiable(_onboardingSteps ?? onboardingSteps);
  }

  @override
  Future<OnboardingStep> upsertOnboardingStep(OnboardingStep step) async {
    final list = List<OnboardingStep>.from(_onboardingSteps ?? onboardingSteps);
    list.removeWhere((s) => s.id == step.id);
    list.add(step);
    _onboardingSteps = list;
    return step;
  }

  @override
  Future<void> deleteOnboardingStep(String id) async {
    final list = List<OnboardingStep>.from(_onboardingSteps ?? onboardingSteps);
    list.removeWhere((s) => s.id == id);
    _onboardingSteps = list;
  }

  @override
  Future<UserSettings> getUserSettings() async => UserSettings(
        locationVisibility: await AppSettingsStore.locationVisibilityName(),
        nearbyDiscoverable: await AppSettingsStore.nearbyDiscoverable(),
        checkInVisible: await AppSettingsStore.checkInVisible(),
        personalization: await AppSettingsStore.personalization(),
        isPrivateProfile: await AppSettingsStore.privateProfile(),
        preferredLanguage: await AppSettingsStore.language(),
      );

  @override
  Future<UserSettings> updateUserSettings({
    String? locationVisibility,
    bool? nearbyDiscoverable,
    bool? checkInVisible,
    bool? personalization,
    bool? isPrivateProfile,
    String? preferredLanguage,
  }) async {
    if (locationVisibility != null) {
      await AppSettingsStore.setLocationVisibilityName(locationVisibility);
    }
    if (nearbyDiscoverable != null) {
      await AppSettingsStore.setNearbyDiscoverable(nearbyDiscoverable);
    }
    if (checkInVisible != null) {
      await AppSettingsStore.setCheckInVisible(checkInVisible);
    }
    if (personalization != null) {
      await AppSettingsStore.setPersonalization(personalization);
    }
    if (isPrivateProfile != null) {
      await AppSettingsStore.setPrivateProfile(isPrivateProfile);
    }
    if (preferredLanguage != null) {
      await AppSettingsStore.setLanguage(preferredLanguage);
    }
    return getUserSettings();
  }

  final Map<String, int> _recentCheckinCounts = {
    // Demo pulse densities stay on seeded place.density (not check-in overrides).
    'carpentry-studio': 0,
    'titan': 0,
    'the-garden': 0,
    'the-kiss': 0,
    'art-rooms': 0,
    'meditation': 0,
  };

  String _densityFromCount(int n) {
    if (n >= 5) return 'busy';
    if (n >= 2) return 'moderate';
    return 'quiet';
  }

  @override
  Future<List<CampusPlace>> getPlaces() async {
    final result = <CampusPlace>[];
    for (final p in _places) {
      final cover = await PlacePhotoStore.photoFor(p.id);
      final count = _recentCheckinCounts[p.id] ?? 0;
      final reviews = _reviews[p.id] ?? const <Review>[];
      final rating = reviews.isEmpty
          ? 0.0
          : reviews.map((r) => r.rating).reduce((a, b) => a + b) / reviews.length;
      result.add(p.copyWith(
        coverUrl: cover,
        recentCheckins: count,
        density: count > 0 ? _densityFromCount(count) : p.density,
        rating: double.parse(rating.toStringAsFixed(1)),
      ));
    }
    return result;
  }

  @override
  Future<void> upsertPlace(CampusPlace place) async {
    final index = _places.indexWhere((p) => p.id == place.id);
    if (index >= 0) {
      _places[index] = place;
    } else {
      _places.add(place);
    }
  }

  @override
  Future<void> deletePlace(String id) async {
    _places.removeWhere((p) => p.id == id);
  }

  @override
  Future<List<CampusEvent>> getEvents({
    bool includeUnpublished = false,
    String? academicYearId,
    String? category,
    String? placeId,
  }) async {
    var base = includeUnpublished ? _events : _events.where((e) => e.isVisibleNow).toList();
    if (academicYearId != null) {
      base = base.where((e) => e.academicYearId == academicYearId).toList();
    }
    if (category != null) {
      base = base.where((e) => e.category == category).toList();
    }
    if (placeId != null) {
      base = base.where((e) => e.placeId == placeId).toList();
    }
    base = [...base]..sort((a, b) {
      final ad = a.eventDate ?? DateTime(1970);
      final bd = b.eventDate ?? DateTime(1970);
      final byDate = ad.compareTo(bd);
      if (byDate != 0) return byDate;
      return a.time.compareTo(b.time);
    });
    return base;
  }

  @override
  Future<List<Quest>> getQuests() async {
    final distinctPlaces = _activity
        .where((a) => a.kind == ActivityKind.checkIn)
        .map((a) => a.title)
        .toSet()
        .length;
    final eventJoins =
        _activity.where((a) => a.kind == ActivityKind.eventJoin).length;
    return [
      for (final q in _quests)
        Quest(
          id: q.id,
          title: q.title,
          subtitle: q.subtitle,
          progress: switch (q.id) {
            'q2' => distinctPlaces > q.target ? q.target : distinctPlaces,
            'q3' => eventJoins > q.target ? q.target : eventJoins,
            _ => q.progress,
          },
          target: q.target,
          reward: q.reward,
        ),
    ];
  }

  Future<void> _evaluateAchievements() async {
    final checkins =
        _activity.where((a) => a.kind == ActivityKind.checkIn).length;
    final distinct = _activity
        .where((a) => a.kind == ActivityKind.checkIn)
        .map((a) => a.title)
        .toSet()
        .length;
    final eventJoins =
        _activity.where((a) => a.kind == ActivityKind.eventJoin).length;
    final reviews =
        _activity.where((a) => a.kind == ActivityKind.review).length;
    final clubJoins = (await AppSettingsStore.joinedClubs()).length;
    final counts = {
      'checkin_count': checkins,
      'distinct_checkins': distinct,
      'event_joins': eventJoins,
      'club_joins': clubJoins,
      'reviews': reviews,
    };
    for (final def in _mutableAchievementDefs) {
      if ((counts[def.triggerKind] ?? 0) >= def.threshold) {
        _unlockedAchievements.add(def.id);
      }
    }
  }

  @override
  Future<List<Achievement>> getAchievements() async {
    await _evaluateAchievements();
    return [
      for (final def in _mutableAchievementDefs)
        Achievement(
          id: def.id,
          title: def.title,
          subtitle: def.subtitle,
          triggerKind: def.triggerKind,
          threshold: def.threshold,
          unlocked: _unlockedAchievements.contains(def.id),
          unlockedAt: _unlockedAchievements.contains(def.id)
              ? DateTime.now()
              : null,
        ),
    ];
  }

  @override
  Future<List<FeedPost>> getFeed() async => List.unmodifiable(_feed);

  PageSlice<T> _pageOf<T>(List<T> all, {int page = 1, int perPage = 20}) {
    final total = all.length;
    final lastPage = total == 0 ? 1 : ((total + perPage - 1) ~/ perPage);
    final safePage = page < 1 ? 1 : page;
    final start = (safePage - 1) * perPage;
    final items =
        start >= total ? <T>[] : all.skip(start).take(perPage).toList();
    return PageSlice(
      items: List.unmodifiable(items),
      currentPage: safePage,
      perPage: perPage,
      total: total,
      lastPage: lastPage,
    );
  }

  @override
  Future<PageSlice<FeedPost>> getFeedPage({int page = 1, int perPage = 20}) async =>
      _pageOf(_feed, page: page, perPage: perPage);

  static const _checkInXp = 10;
  static const _checkInRadiusMeters = 150.0;
  final Map<String, DateTime> _lastCheckinAt = {};

  @override
  Future<void> checkIn(
    String placeId, {
    bool visibleToOthers = true,
    required double latitude,
    required double longitude,
    double? accuracy,
  }) async {
    CampusPlace? place;
    for (final p in _places) {
      if (p.id == placeId) {
        place = p;
        break;
      }
    }
    if (place == null) {
      throw ApiClientException('Place not found', code: 'PLACE_NOT_FOUND', statusCode: 404);
    }
    final meters = _distanceMeters(latitude, longitude, place.lat, place.lng);
    if (meters > _checkInRadiusMeters) {
      throw ApiClientException(
        'You are too far from this place to check in.',
        code: 'CHECKIN_TOO_FAR',
        statusCode: 403,
      );
    }
    final last = _lastCheckinAt[placeId];
    if (last != null && DateTime.now().difference(last).inMinutes < 30) {
      throw ApiClientException(
        'You already checked in at this place recently.',
        code: 'ALREADY_CHECKED_IN',
        statusCode: 409,
      );
    }
    _lastCheckinAt[placeId] = DateTime.now();
    final placeName = place.name;
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
    _recentCheckinCounts[placeId] = (_recentCheckinCounts[placeId] ?? 0) + 1;
    await _evaluateAchievements();
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
    await _evaluateAchievements();
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
  Future<void> setPlaceCover(String placeId, String url) =>
      PlacePhotoStore.setPhoto(placeId, url);

  final Map<String, List<WorkshopEquipmentItem>> _workshopEquipment = {};
  final Map<String, List<CampusCollaborationPost>> _collaborationPosts = {};

  @override
  Future<WorkshopInfo> getWorkshopInfo(String placeId) async {
    return WorkshopInfo(
      equipment: List.unmodifiable(_workshopEquipment[placeId] ?? const []),
      posts: List.unmodifiable(_collaborationPosts[placeId] ?? const []),
    );
  }

  @override
  Future<CampusCollaborationPost> addCollaborationPost(
      String placeId, String text) async {
    assertTextAllowed(text);
    final post = CampusCollaborationPost(
      id: 'collab-${DateTime.now().millisecondsSinceEpoch}',
      authorId: _user.id,
      authorName: _user.name,
      text: text,
      createdAt: DateTime.now(),
    );
    _collaborationPosts.putIfAbsent(placeId, () => []).insert(0, post);
    return post;
  }

  @override
  Future<WorkshopEquipmentItem> upsertWorkshopEquipment(String placeId,
      {String? id,
      required String name,
      bool available = true,
      int sortOrder = 0}) async {
    final list = _workshopEquipment.putIfAbsent(placeId, () => []);
    final item = WorkshopEquipmentItem(
      id: id ?? 'equip-${DateTime.now().millisecondsSinceEpoch}',
      name: name,
      available: available,
    );
    list.removeWhere((e) => e.id == item.id);
    list.add(item);
    return item;
  }

  @override
  Future<void> deleteWorkshopEquipment(String placeId, String itemId) async {
    _workshopEquipment[placeId]?.removeWhere((e) => e.id == itemId);
  }

  @override
  Future<void> deleteCollaborationPost(String placeId, String postId) async {
    _collaborationPosts[placeId]?.removeWhere((p) => p.id == postId);
  }

  // Mock mode has no backend, so the hardcoded `shuttleRoutes` const is a
  // legitimate offline seed here (REST mode is the real, admin-editable
  // source of truth — see ShuttleController).
  List<ShuttleRoute>? _shuttleRoutes;

  @override
  Future<List<ShuttleRoute>> getShuttleRoutes() async {
    return List.unmodifiable(_shuttleRoutes ?? shuttleRoutes);
  }

  @override
  Future<ShuttleRoute> upsertShuttleRoute(ShuttleRoute route) async {
    final list = List<ShuttleRoute>.from(_shuttleRoutes ?? shuttleRoutes);
    list.removeWhere((r) => r.id == route.id);
    list.add(route);
    _shuttleRoutes = list;
    return route;
  }

  @override
  Future<void> deleteShuttleRoute(String id) async {
    final list = List<ShuttleRoute>.from(_shuttleRoutes ?? shuttleRoutes);
    list.removeWhere((r) => r.id == id);
    _shuttleRoutes = list;
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
      await _evaluateAchievements();
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

  // Mock mode has no multi-account/department concept (MockAuthProvider
  // only distinguishes superAdmin vs student) — the Trainer Panel demo
  // here always acts as this one fixed department, matching the seeded
  // 'staff-arch-head' StaffProfile used elsewhere in this file.
  static const _mockTrainerStaffId = 'staff-arch-head';

  @override
  Future<List<CampusEvent>> getTrainerEvents() async {
    return _events.where((e) => e.responsibleStaffId == _mockTrainerStaffId).toList();
  }

  @override
  Future<CampusEvent> upsertTrainerEvent(CampusEvent event, {required bool isNew}) async {
    if (event.placeId != null) {
      final conflict = _placeConflict(event.placeId!, event.eventDate, event.time,
          excludeEventId: isNew ? null : event.id);
      if (conflict != null) {
        throw PlaceConflictException(
            'Bu mekân o tarihte ve saatte dolu: "${conflict.title}".');
      }
    }
    final saved = CampusEvent(
      id: isNew ? 'event-${DateTime.now().millisecondsSinceEpoch}' : event.id,
      title: event.title,
      time: event.time,
      eventDate: event.eventDate,
      placeName: event.placeName,
      placeId: event.placeId,
      category: event.category,
      attendees: 0,
      xp: 20,
      draft: false,
      workflowStatus: 'published',
      audience: 'Tümü',
      organizer: event.organizer,
      description: event.description,
      responsibleStaffId: _mockTrainerStaffId,
    );
    final index = _events.indexWhere((e) => e.id == saved.id);
    if (index == -1) {
      _events.add(saved);
    } else {
      _events[index] = saved;
    }
    return saved;
  }

  @override
  Future<void> deleteTrainerEvent(String id) async {
    _events.removeWhere((e) => e.id == id && e.responsibleStaffId == _mockTrainerStaffId);
  }

  @override
  Future<List<ParticipationApplication>> getTrainerApplications({String? status}) async {
    return _applications.where((a) {
      if (a.responsibleStaffId != _mockTrainerStaffId) return false;
      if (status != null) return a.status == status;
      return a.status == ParticipationApplication.statusDetailFormPending ||
          a.status == ParticipationApplication.statusDetailFormSubmitted ||
          a.status == ParticipationApplication.statusUnderReview ||
          a.status == ParticipationApplication.statusRevisionRequired;
    }).toList();
  }

  ParticipationApplication _updateTrainerApplication(String id, String status, String? reviewNote) {
    final i = _applications.indexWhere((a) => a.id == id && a.responsibleStaffId == _mockTrainerStaffId);
    final old = _applications[i];
    final next = ParticipationApplication(
      id: old.id,
      userId: old.userId,
      studentName: old.studentName,
      studentDepartment: old.studentDepartment,
      targetType: old.targetType,
      targetId: old.targetId,
      status: status,
      responsibleStaffId: old.responsibleStaffId,
      responsibleStaffName: old.responsibleStaffName,
      formPayload: old.formPayload,
      detailPayload: old.detailPayload,
      reviewNote: reviewNote,
      submittedAt: old.submittedAt,
    );
    _applications[i] = next;
    return next;
  }

  @override
  Future<ParticipationApplication> approveTrainerApplication(String id, {String? reviewNote}) async {
    return _updateTrainerApplication(id, ParticipationApplication.statusApproved, reviewNote);
  }

  @override
  Future<ParticipationApplication> rejectTrainerApplication(String id, {required String reviewNote}) async {
    return _updateTrainerApplication(id, ParticipationApplication.statusRejected, reviewNote);
  }

  @override
  Future<ParticipationApplication> requestTrainerApplicationRevision(String id, {required String reviewNote}) async {
    return _updateTrainerApplication(id, ParticipationApplication.statusRevisionRequired, reviewNote);
  }

  @override
  Future<List<StaffProfile>> getTrainerRoster() async {
    final me = _staff.firstWhere((s) => s.id == _mockTrainerStaffId);
    return _staff.where((s) => s.active && s.department == me.department).toList();
  }

  @override
  Future<List<CampusClub>> getClubs({String? category}) async {
    final clubs = await AdminContentStore.clubs();
    final joined = await AppSettingsStore.joinedClubs();
    final withCounts = clubs
        .map((c) => CampusClub(
              id: c.id,
              name: c.name,
              category: c.category,
              description: c.description,
              body: c.body,
              memberCount: joined.contains(c.id) ? 1 : 0,
            ))
        .toList();
    if (category == null) return withCounts;
    return withCounts.where((c) => c.category == category).toList();
  }

  @override
  Future<void> upsertClub(CampusClub club) => AdminContentStore.saveClub(club);

  @override
  Future<void> deleteClub(String id) => AdminContentStore.deleteClub(id);

  // Mock mode keeps the previous on-device-only behavior (no shared
  // roster exists to migrate to) — see AppSettingsStore.joinedClubs's own
  // doc comment for why this was already a genuine, persisted local join
  // rather than a UI-only toggle.
  @override
  Future<Set<String>> getJoinedClubIds() => AppSettingsStore.joinedClubs();

  @override
  Future<void> joinClub(String clubId) async {
    await AppSettingsStore.setClubJoined(clubId, true);
    await _evaluateAchievements();
  }

  @override
  Future<void> leaveClub(String clubId) => AppSettingsStore.setClubJoined(clubId, false);

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
  Future<List<CampusFoodVenue>> getFoodVenues() => AdminContentStore.foodVenues();

  @override
  Future<void> upsertFoodVenue(CampusFoodVenue venue) =>
      AdminContentStore.saveFoodVenue(venue);

  @override
  Future<void> deleteFoodVenue(String id) => AdminContentStore.deleteFoodVenue(id);

  @override
  Future<void> upsertFoodMenu(String venueId, DailyMenu menu) async {
    final venues = await AdminContentStore.foodVenues();
    final venue = venues.firstWhere((v) => v.id == venueId);
    final next = [
      for (final m in venue.dailyMenus)
        if (m.date.year != menu.date.year ||
            m.date.month != menu.date.month ||
            m.date.day != menu.date.day)
          m,
      menu,
    ];
    await AdminContentStore.saveFoodVenue(CampusFoodVenue(
      id: venue.id,
      name: venue.name,
      hours: venue.hours,
      dailyMenus: next,
      menuFileUrl: venue.menuFileUrl,
    ));
  }

  @override
  Future<void> deleteFoodMenu(String venueId, DateTime date) async {
    final venues = await AdminContentStore.foodVenues();
    final venue = venues.firstWhere((v) => v.id == venueId);
    await AdminContentStore.saveFoodVenue(CampusFoodVenue(
      id: venue.id,
      name: venue.name,
      hours: venue.hours,
      dailyMenus: [
        for (final m in venue.dailyMenus)
          if (m.date.year != date.year || m.date.month != date.month || m.date.day != date.day)
            m,
      ],
      menuFileUrl: venue.menuFileUrl,
    ));
  }

  @override
  Future<List<MediaItem>> getMedia() => MediaLibraryStore.items();

  @override
  Future<MediaItem> uploadMedia(Uint8List bytes, {required String fileName}) =>
      MediaLibraryStore.upload(bytes, fileName: fileName, uploadedBy: 'Admin');

  @override
  Future<void> renameMedia(String id, String fileName) =>
      MediaLibraryStore.rename(id, fileName);

  @override
  Future<void> deleteMedia(String id) => MediaLibraryStore.delete(id);

  @override
  Future<void> markMediaUsed(String id, String ref) =>
      MediaLibraryStore.markUsed(id, ref);

  @override
  Future<PageSlice<MediaItem>> getMyMediaPage({int page = 1, int perPage = 20}) async {
    final all = await MediaLibraryStore.personalItems();
    return _pageOf(all, page: page, perPage: perPage);
  }

  @override
  Future<MediaItem> uploadMyMedia(Uint8List bytes, {required String fileName}) =>
      MediaLibraryStore.uploadPersonal(
        bytes,
        fileName: fileName,
        uploadedBy: _user.name,
      );

  @override
  Future<void> deleteMyMedia(String id) => MediaLibraryStore.deletePersonal(id);

  @override
  Future<PageSlice<CareerOpportunity>> getCareerOpportunitiesPage(
      {int page = 1, int perPage = 20}) async {
    final published =
        _careerOpportunities.where((o) => o.published).toList();
    return _pageOf(published, page: page, perPage: perPage);
  }

  @override
  Future<CareerProfile> getCareerProfile() async => _careerProfile;

  @override
  Future<CareerProfile> updateCareerProfile({
    String? occupation,
    String? headline,
    String? expertise,
    String? cvUrl,
    bool? lookingForInternships,
    bool? lookingForJobs,
  }) async {
    _careerProfile = CareerProfile(
      occupation: occupation ?? headline ?? _careerProfile.occupation,
      expertise: expertise ?? _careerProfile.expertise,
      hasCv: _careerProfile.hasCv || (cvUrl != null && cvUrl.isNotEmpty),
      cvFileName: _careerProfile.cvFileName,
      lookingForInternships:
          lookingForInternships ?? _careerProfile.lookingForInternships,
      lookingForJobs: lookingForJobs ?? _careerProfile.lookingForJobs,
      updatedAt: DateTime.now(),
    );
    return _careerProfile;
  }

  @override
  Future<void> upsertCareerOpportunity(CareerOpportunity opportunity) async {
    final index = _careerOpportunities.indexWhere((o) => o.id == opportunity.id);
    if (index >= 0) {
      _careerOpportunities[index] = opportunity;
    } else {
      _careerOpportunities.insert(0, opportunity);
    }
  }

  @override
  Future<void> deleteCareerOpportunity(String id) async {
    _careerOpportunities.removeWhere((o) => o.id == id);
  }

  final List<StaffProfile> _staff = [
    const StaffProfile(
      id: 'staff-arch-head',
      name: 'Mimarlık Bölüm Başkanlığı',
      faculty: 'Fine Arts',
      department: 'Architecture',
      title: 'Department Head',
      isDepartmentHead: true,
    ),
    const StaffProfile(
      id: 'staff-clubs',
      name: 'Kulüp Koordinatörü',
      department: 'Student Affairs',
      title: 'Club Responsible',
    ),
  ];
  final List<ParticipationApplication> _applications = [];
  final List<AppointmentBooking> _appointments = [];

  @override
  Future<List<StaffProfile>> getStaff({
    String? q,
    String? faculty,
    String? department,
    String? title,
    bool departmentHeadOnly = false,
  }) async {
    return _staff.where((s) {
      if (!s.active) return false;
      if (departmentHeadOnly && !s.isDepartmentHead) return false;
      if (department != null && s.department != department) return false;
      if (faculty != null && s.faculty != faculty) return false;
      if (title != null && !(s.title ?? '').contains(title)) return false;
      if (q != null && q.isNotEmpty) {
        final hay = '${s.name} ${s.department} ${s.title}'.toLowerCase();
        if (!hay.contains(q.toLowerCase())) return false;
      }
      return true;
    }).toList();
  }

  @override
  Future<List<StaffProfile>> getAdminStaff({String? q, String? department, bool? active}) async {
    return _staff.where((s) {
      if (active != null && s.active != active) return false;
      if (department != null && s.department != department) return false;
      return true;
    }).toList();
  }

  @override
  Future<void> upsertStaffProfile(StaffProfile staff) async {
    final i = _staff.indexWhere((s) => s.id == staff.id);
    if (i == -1) {
      _staff.add(staff);
    } else {
      _staff[i] = staff;
    }
  }

  @override
  Future<void> deleteStaffProfile(String id) async {
    _staff.removeWhere((s) => s.id == id);
  }

  @override
  Future<List<ParticipationApplication>> getMyApplications() async =>
      List.unmodifiable(_applications.where((a) => a.userId == _user.id));

  @override
  Future<ParticipationApplication> submitApplication({
    required String targetType,
    required String targetId,
    String? responsibleStaffId,
    Map<String, dynamic>? formPayload,
  }) async {
    final open = _applications.any((a) =>
        a.userId == _user.id &&
        a.targetType == targetType &&
        a.targetId == targetId &&
        a.isOpen);
    if (open) {
      throw ApiClientException('Already applied', code: 'ALREADY_APPLIED', statusCode: 409);
    }
    final app = ParticipationApplication(
      id: 'app-${DateTime.now().millisecondsSinceEpoch}',
      userId: _user.id,
      studentName: _user.name,
      targetType: targetType,
      targetId: targetId,
      status: ParticipationApplication.statusDetailFormPending,
      responsibleStaffId: responsibleStaffId ?? 'staff-clubs',
      formPayload: formPayload ?? {},
      submittedAt: DateTime.now(),
    );
    _applications.insert(0, app);
    return app;
  }

  @override
  Future<ParticipationApplication> submitApplicationDetail(
    String applicationId, {
    Map<String, dynamic>? formPayload,
  }) async {
    final i = _applications.indexWhere((a) => a.id == applicationId);
    final old = _applications[i];
    final next = ParticipationApplication(
      id: old.id,
      userId: old.userId,
      studentName: old.studentName,
      studentDepartment: old.studentDepartment,
      targetType: old.targetType,
      targetId: old.targetId,
      status: ParticipationApplication.statusUnderReview,
      responsibleStaffId: old.responsibleStaffId,
      responsibleStaffName: old.responsibleStaffName,
      formPayload: old.formPayload,
      detailPayload: formPayload ?? {},
      submittedAt: old.submittedAt,
      targetLabel: old.targetLabel,
    );
    _applications[i] = next;
    return next;
  }

  @override
  Future<List<ApplicationQuestion>> getApplicationQuestions(String targetType, String stage) async {
    // Mock mode's own small representative set — real question
    // management lives server-side (ApplicationQuestion, admin-editable);
    // this only exists so the offline demo still shows a real dynamic
    // form instead of nothing.
    if (stage == 'detail') {
      return [
        ApplicationQuestion(
          id: 'mock-detail-explain',
          targetType: targetType,
          stage: 'detail',
          type: 'textarea',
          label: 'Durumunuzu detaylı açıklayın',
          required: true,
          sortOrder: 1,
        ),
      ];
    }
    return switch (targetType) {
      'sport' => const [
          ApplicationQuestion(
            id: 'mock-sport-before', targetType: 'sport', stage: 'preview',
            type: 'single_choice', label: 'Bu spor dalıyla daha önce ilgilendiniz mi?',
            options: ['Evet', 'Hayır'], required: true, sortOrder: 1,
          ),
          ApplicationQuestion(
            id: 'mock-sport-purpose', targetType: 'sport', stage: 'preview',
            type: 'single_choice', label: 'Katılım amacınız nedir?',
            options: ['Rekabetçi', 'Hobi', 'Sosyalleşme'], required: true, sortOrder: 2,
          ),
        ],
      'club' || 'community' => const [
          ApplicationQuestion(
            id: 'mock-club-purpose', targetType: 'club', stage: 'preview',
            type: 'textarea', label: 'Bu kulübe katılma amacınız', required: true, sortOrder: 1,
          ),
        ],
      'career' => const [
          ApplicationQuestion(
            id: 'mock-career-field', targetType: 'career', stage: 'preview',
            type: 'text', label: 'İlgilendiğiniz kariyer alanı', required: true, sortOrder: 1,
          ),
          ApplicationQuestion(
            id: 'mock-career-why', targetType: 'career', stage: 'preview',
            type: 'textarea', label: 'Neden bu fırsata başvuruyorsunuz?', required: true, sortOrder: 2,
          ),
        ],
      'help' || 'service' => const [
          ApplicationQuestion(
            id: 'mock-help-topic', targetType: 'help', stage: 'preview',
            type: 'textarea', label: 'Hangi konuda yardıma ihtiyacınız var?', required: true, sortOrder: 1,
          ),
        ],
      'event' => const [
          ApplicationQuestion(
            id: 'mock-event-purpose', targetType: 'event', stage: 'preview',
            type: 'single_choice', label: 'Etkinliğe katılma amacınız',
            options: ['Bilgi edinmek', 'Ağ kurmak', 'Eğlence', 'Zorunlu'], required: true, sortOrder: 1,
          ),
        ],
      _ => const [],
    };
  }

  @override
  Future<List<Map<String, dynamic>>> getApplicationHistory(String applicationId) async => const [];

  @override
  Future<List<ParticipationApplication>> getAdminApplications({String? status, String? targetType}) async {
    return _applications.where((a) {
      if (status != null && a.status != status) return false;
      if (targetType != null && a.targetType != targetType) return false;
      return true;
    }).toList();
  }

  @override
  Future<ParticipationApplication> approveApplication(String id, {String? reviewNote}) async {
    final i = _applications.indexWhere((a) => a.id == id);
    final old = _applications[i];
    final next = ParticipationApplication(
      id: old.id,
      userId: old.userId,
      studentName: old.studentName,
      studentDepartment: old.studentDepartment,
      targetType: old.targetType,
      targetId: old.targetId,
      status: ParticipationApplication.statusApproved,
      responsibleStaffId: old.responsibleStaffId,
      responsibleStaffName: old.responsibleStaffName,
      formPayload: old.formPayload,
      detailPayload: old.detailPayload,
      reviewNote: reviewNote,
      submittedAt: old.submittedAt,
    );
    _applications[i] = next;
    if (old.targetType == 'club' || old.targetType == 'community') {
      await AppSettingsStore.setClubJoined(old.targetId, true);
    }
    return next;
  }

  @override
  Future<ParticipationApplication> rejectApplication(String id, {String? reviewNote}) async {
    final i = _applications.indexWhere((a) => a.id == id);
    final old = _applications[i];
    final next = ParticipationApplication(
      id: old.id,
      userId: old.userId,
      studentName: old.studentName,
      studentDepartment: old.studentDepartment,
      targetType: old.targetType,
      targetId: old.targetId,
      status: ParticipationApplication.statusRejected,
      responsibleStaffId: old.responsibleStaffId,
      responsibleStaffName: old.responsibleStaffName,
      formPayload: old.formPayload,
      detailPayload: old.detailPayload,
      reviewNote: reviewNote,
      submittedAt: old.submittedAt,
    );
    _applications[i] = next;
    return next;
  }

  @override
  Future<ParticipationApplication> requestApplicationRevision(String id, {required String reviewNote}) async {
    final i = _applications.indexWhere((a) => a.id == id);
    final old = _applications[i];
    final next = ParticipationApplication(
      id: old.id,
      userId: old.userId,
      studentName: old.studentName,
      studentDepartment: old.studentDepartment,
      targetType: old.targetType,
      targetId: old.targetId,
      status: ParticipationApplication.statusRevisionRequired,
      responsibleStaffId: old.responsibleStaffId,
      responsibleStaffName: old.responsibleStaffName,
      formPayload: old.formPayload,
      detailPayload: old.detailPayload,
      reviewNote: reviewNote,
      submittedAt: old.submittedAt,
    );
    _applications[i] = next;
    return next;
  }

  @override
  Future<List<AppointmentBooking>> getMyAppointments() async =>
      List.unmodifiable(_appointments);

  @override
  Future<List<AppointmentBooking>> getAdminAppointments({
    String? staffProfileId,
    String? status,
    String? q,
    String? department,
  }) async {
    return _appointments.where((a) {
      if (staffProfileId != null && a.staffProfileId != staffProfileId) return false;
      if (status != null && a.status != status) return false;
      if (q != null && q.isNotEmpty) {
        final hay = '${a.subject ?? ''} ${a.studentName ?? ''} ${a.staffName ?? ''}'.toLowerCase();
        if (!hay.contains(q.toLowerCase())) return false;
      }
      return true;
    }).toList();
  }

  @override
  Future<List<StaffSlot>> getStaffSlots(String staffProfileId, {String? date}) async {
    final selectedDate = date ?? DateTime.now().toIso8601String().split('T').first;
    const slots = [
      ('10:00', '10:30'),
      ('11:00', '11:30'),
      ('14:00', '14:30'),
      ('15:00', '15:30'),
    ];
    return slots
        .map((s) {
          final booked = _appointments.any((a) =>
              a.staffProfileId == staffProfileId &&
              a.date == selectedDate &&
              a.startTime == s.$1 &&
              a.status == 'booked');
          final status = booked ? 'booked' : 'available';
          return StaffSlot(
            id: 'slot-$staffProfileId-$selectedDate-${s.$1}',
            staffProfileId: staffProfileId,
            date: selectedDate,
            startTime: s.$1,
            endTime: s.$2,
            available: !booked,
            status: status,
          );
        })
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
    final clash = _appointments.any((a) =>
        a.staffProfileId == staffProfileId &&
        a.date == date &&
        a.startTime == startTime &&
        (a.status == 'booked' || a.status == 'pending' || a.status == 'approved'));
    if (clash) {
      throw ApiClientException('Slot taken', code: 'SLOT_UNAVAILABLE', statusCode: 409);
    }
    final appt = AppointmentBooking(
      id: 'appt-${DateTime.now().millisecondsSinceEpoch}',
      staffProfileId: staffProfileId,
      staffName: _staff.where((s) => s.id == staffProfileId).firstOrNull?.name,
      date: date,
      startTime: startTime,
      endTime: endTime,
      status: 'pending',
      subject: subject,
      notes: notes,
      createdAt: DateTime.now(),
    );
    _appointments.add(appt);
    return appt;
  }

  @override
  Future<void> cancelAppointment(String id) async {
    final i = _appointments.indexWhere((a) => a.id == id);
    if (i == -1) return;
    final old = _appointments[i];
    _appointments[i] = AppointmentBooking(
      id: old.id,
      staffProfileId: old.staffProfileId,
      staffName: old.staffName,
      studentName: old.studentName,
      date: old.date,
      startTime: old.startTime,
      endTime: old.endTime,
      status: 'cancelled',
      subject: old.subject,
      notes: old.notes,
      adminNotes: old.adminNotes,
      createdAt: old.createdAt,
    );
  }

  @override
  Future<List<Achievement>> getAdminAchievements() async => getAchievements();

  @override
  Future<void> upsertAchievementDefinition(Achievement definition) async {
    final i = _mutableAchievementDefs.indexWhere((a) => a.id == definition.id);
    if (i == -1) {
      _mutableAchievementDefs.add(definition);
    } else {
      _mutableAchievementDefs[i] = definition;
    }
  }

  @override
  Future<void> deleteAchievementDefinition(String id) async {
    _mutableAchievementDefs.removeWhere((a) => a.id == id);
  }

  @override
  Future<List<DirectoryEntry>> getDirectoryEntries() => BuildingDirectoryStore.entries();

  @override
  Future<List<CampusBuilding>> getDirectoryBuildings() async {
    final entries = await BuildingDirectoryStore.entries();
    final counts = <String, int>{};
    for (final e in entries) {
      counts[e.building] = (counts[e.building] ?? 0) + 1;
    }
    final names = counts.keys.toList()..sort();
    return [
      for (final name in names)
        CampusBuilding(id: name, name: name, entryCount: counts[name]!),
    ];
  }

  @override
  Future<List<CampusFloor>> getDirectoryFloors(String building) async {
    final entries = await BuildingDirectoryStore.entries();
    final inBuilding = entries.where((e) => e.building == building).toList();
    if (inBuilding.isEmpty) {
      throw StateError('BUILDING_NOT_FOUND');
    }
    final counts = <String, int>{};
    for (final e in inBuilding) {
      final floor = e.floor;
      if (floor == null || floor.isEmpty) continue;
      counts[floor] = (counts[floor] ?? 0) + 1;
    }
    final floors = counts.keys.toList()..sort();
    return [
      for (final f in floors)
        CampusFloor(id: f, name: f, building: building, entryCount: counts[f]!),
    ];
  }

  @override
  Future<List<CampusRoom>> getDirectoryRooms(String building, String floor) async {
    final entries = await BuildingDirectoryStore.entries();
    final rooms = entries
        .where((e) => e.building == building && e.floor == floor)
        .map((e) => CampusRoom(
              id: e.id,
              room: e.room,
              building: e.building,
              floor: e.floor,
              occupantName: e.occupantName,
              occupantRole: e.occupantRole,
              relatedServiceId: e.relatedServiceId,
            ))
        .toList();
    if (rooms.isEmpty) {
      throw StateError('FLOOR_NOT_FOUND');
    }
    return rooms;
  }

  @override
  Future<WalkingRoute?> getWalkingRoute({
    required double fromLat,
    required double fromLng,
    required double toLat,
    required double toLng,
  }) async {
    // Mock has no OSRM provider — honest null so UI uses straight-line fallback.
    return null;
  }

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
  Future<PageSlice<AuditLogEntry>> getAuditLogPage({int page = 1, int perPage = 20}) async =>
      _pageOf(await AuditLogStore.entries(), page: page, perPage: perPage);

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
  Future<Set<String>> getFollowers() async => const {};

  @override
  Future<Set<String>> getBlocked() => SocialGraphStore.blocked();

  @override
  Future<bool> toggleFollow(String peer) => SocialGraphStore.toggleFollow(peer);

  @override
  Future<bool> toggleBlock(String peer) => SocialGraphStore.toggleBlock(peer);

  @override
  Future<void> reportUser(String peer, String reason) async {}

  @override
  Future<List<FollowRequestPeer>> getFollowRequests() async => const [];

  @override
  Future<void> acceptFollowRequest(String peer) async {}

  @override
  Future<void> declineFollowRequest(String peer) async {}

  @override
  Future<List<ChatThreadPeer>> getChatThreadPeers(List<String> knownPeers) async {
    final names = await ChatStore.threadPeers(_user.name, knownPeers);
    // Mock mode has no per-peer avatar data source (only the signed-in
    // user's own avatar is ever set) — honestly reports null rather than
    // fabricating a photo.
    final muted = await SocialLocalPrefs.mutedPeers();
    final archived = await SocialLocalPrefs.archivedPeers();
    final restricted = await SocialLocalPrefs.restrictedPeers();
    return names
        .map((n) => ChatThreadPeer(
              name: n,
              muted: muted.contains(n),
              archived: archived.contains(n),
              restricted: restricted.contains(n),
            ))
        .toList();
  }

  @override
  Future<List<ChatMessage>> getChatMessages(String peer) =>
      ChatStore.messages(_user.name, peer);

  @override
  Future<ChatMessage> sendChatMessage(String peer, String text) async {
    final saved = await ChatStore.send(_user.name, peer, text);
    InMemoryChatConnector.instance.publish({
      'id': saved.id,
      'fromMe': saved.fromMe,
      'text': saved.text,
      'sentAt': saved.sentAt.toIso8601String(),
      'sender': _user.name,
      'peer': peer,
      'conversationId': 'mock',
    });
    return saved.copyWith(conversationId: 'mock', sender: _user.name);
  }

  @override
  Future<ChatThreadPrefs> getChatPrefs() async {
    final muted = await SocialLocalPrefs.mutedPeers();
    final archived = await SocialLocalPrefs.archivedPeers();
    final restricted = await SocialLocalPrefs.restrictedPeers();
    final peers = {...muted, ...archived, ...restricted};
    final map = <String, ChatThreadPrefState>{};
    for (final peer in peers) {
      map[peer] = ChatThreadPrefState(
        peer: peer,
        muted: muted.contains(peer),
        archived: archived.contains(peer),
        restricted: restricted.contains(peer),
      );
    }
    return ChatThreadPrefs(map);
  }

  @override
  Future<ChatThreadPrefState> toggleChatPref(String peer, String field) async {
    switch (field) {
      case 'mute':
        await SocialLocalPrefs.toggleMute(peer);
      case 'archive':
        await SocialLocalPrefs.toggleArchive(peer);
      case 'restrict':
        await SocialLocalPrefs.toggleRestrict(peer);
    }
    final muted = await SocialLocalPrefs.mutedPeers();
    final archived = await SocialLocalPrefs.archivedPeers();
    final restricted = await SocialLocalPrefs.restrictedPeers();
    return ChatThreadPrefState(
      peer: peer,
      muted: muted.contains(peer),
      archived: archived.contains(peer),
      restricted: restricted.contains(peer),
    );
  }

  @override
  Future<List<ChatGroup>> getChatGroups() async {
    final rows = await SocialLocalPrefs.groups();
    final muted = await SocialLocalPrefs.mutedGroupIds();
    final archived = await SocialLocalPrefs.archivedGroupIds();
    return rows
        .map((g) => ChatGroup(
              id: '${g['id']}',
              name: '${g['name']}',
              members: (g['members'] as List?)?.cast<String>() ?? const [],
              muted: muted.contains('${g['id']}'),
              archived: archived.contains('${g['id']}'),
            ))
        .toList();
  }

  @override
  Future<ChatGroup> createChatGroup(String name, List<String> members) async {
    final id = 'grp-${DateTime.now().millisecondsSinceEpoch}';
    await SocialLocalPrefs.saveGroup(id: id, name: name, members: members);
    return ChatGroup(id: id, name: name, members: members);
  }

  @override
  Future<List<ChatMessage>> getGroupMessages(String groupId) async => const [];

  @override
  Future<ChatMessage> sendGroupMessage(String groupId, String text) async {
    return ChatMessage(
      id: 'gmsg-${DateTime.now().microsecondsSinceEpoch}',
      fromMe: true,
      text: text,
      sender: _user.name,
      conversationId: groupId,
    );
  }

  @override
  Future<void> leaveChatGroup(String id) async {
    await SocialLocalPrefs.deleteGroup(id);
  }

  @override
  Future<ChatGroup> toggleChatGroupPref(String id, String field) async {
    var muted = (await SocialLocalPrefs.mutedGroupIds()).contains(id);
    var archived = (await SocialLocalPrefs.archivedGroupIds()).contains(id);
    switch (field) {
      case 'mute':
        muted = await SocialLocalPrefs.toggleMuteGroup(id);
      case 'archive':
        archived = await SocialLocalPrefs.toggleArchiveGroup(id);
    }
    final groups = await getChatGroups();
    final existing = groups.where((g) => g.id == id).firstOrNull;
    return (existing ?? ChatGroup(id: id, name: id)).copyWith(
      muted: muted,
      archived: archived,
    );
  }

  @override
  Future<void> reportChatGroup(String id, String reason) async {}

  // Mock mode has no real backend notification pipeline — no other real
  // user exists to generate one (same honest scope as NotificationsScreen
  // itself), so this stays genuinely empty rather than faking entries.
  @override
  Future<List<InboxNotification>> getInboxNotifications() async => const [];

  @override
  Future<PageSlice<InboxNotification>> getInboxNotificationsPage(
          {int page = 1, int perPage = 20}) async =>
      _pageOf(const <InboxNotification>[], page: page, perPage: perPage);

  @override
  Future<void> markNotificationRead(String id) async {}

  @override
  Future<void> markAllNotificationsRead() async {}

  @override
  Future<void> registerPushToken({required String token, required String platform}) async {}

  @override
  Future<void> unregisterPushToken(String token) async {}

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
    required String responsibleStaffId,
    String time = '',
    DateTime? eventDate,
    String category = 'Öğrenci Etkinliği',
    String description = '',
  }) async {
    final place = (await getPlaces()).where((p) => p.id == placeId).firstOrNull;
    if (place == null) {
      throw StateError('placeId must reference a real, admin-defined place.');
    }
    final staff = _staff.where((s) => s.id == responsibleStaffId).firstOrNull;
    if (staff == null || !staff.isDepartmentHead || !staff.active) {
      throw StateError('responsibleStaffId must be an active department head.');
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
      responsibleStaffId: staff.id,
      responsibleStaffName: staff.name,
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

  @override
  Future<List<EventParticipant>> getTrainerEventParticipants(String eventId) =>
      getEventParticipants(eventId);

  @override
  Future<void> approveTrainerEventParticipant(String eventId, String joinId) =>
      approveEventParticipant(eventId, joinId);

  final List<EmailLogEntry> _emailLogs = [];

  @override
  Future<List<EmailLogEntry>> getEmailLogs() async =>
      List.unmodifiable(_emailLogs.reversed);

  @override
  Future<PageSlice<EmailLogEntry>> getEmailLogsPage({int page = 1, int perPage = 20}) async =>
      _pageOf(await getEmailLogs(), page: page, perPage: perPage);

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
  Future<PageSlice<MediaItem>> getModerationQueue({int page = 1, int perPage = 20}) async {
    final pending = (await MediaLibraryStore.items())
        .where((m) => m.moderationStatus == 'pending')
        .toList();
    return _pageOf(pending, page: page, perPage: perPage);
  }

  @override
  Future<void> resolveModerationQueueItem(String id, {required String action}) async {
    final items = await MediaLibraryStore.items();
    final item = items.firstWhere((m) => m.id == id);
    if (item.moderationStatus != 'pending') {
      throw StateError('ALREADY_REVIEWED');
    }
    await MediaLibraryStore.setModerationStatus(id, action);
    if (action == 'rejected') {
      await MediaLibraryStore.delete(id);
    }
  }

  @override
  Future<CampusEvent> draftEventFromPoster(Uint8List bytes, {required String fileName}) async {
    // Mock: local draft only — never auto-publishes. Not a production AI fallback.
    final title = fileName.replaceAll(RegExp(r'\.[^.]+$'), '').replaceAll('_', ' ');
    final event = CampusEvent(
      id: 'event-ai-${DateTime.now().microsecondsSinceEpoch}',
      title: title.isEmpty ? 'Poster taslağı' : title,
      time: '',
      placeName: '',
      category: 'Etkinlik',
      attendees: 0,
      xp: 30,
      draft: true,
      workflowStatus: 'draft',
      description: 'Mock poster draft (AI not configured locally).',
      aiDraft: true,
    );
    _events.add(event);
    return event;
  }

  @override
  Future<bool> getImageModerationConfigured() async => false;

  @override
  Future<bool> setImageModerationApiKey(String apiKey) async => false;

  @override
  Future<SystemHealth> getSystemHealth() async => const SystemHealth(
        database: true,
        routingConfigured: false,
        moderationConfigured: false,
        entraConfigured: false,
        wordpressConfigured: false,
        aiConfigured: false,
        broadcastingConfigured: false,
      );

  @override
  Future<SiteSettings> getSiteSettings() async {
    final entra = await SiteSettingsStore.entra();
    final wp = await SiteSettingsStore.wordpress();
    return SiteSettings(
      entra: entra,
      wordpressSiteUrl: wp.siteUrl,
      wordpressApiTokenConfigured: wp.apiToken.isNotEmpty,
      wordpressApiToken: wp.apiToken,
    );
  }

  @override
  Future<SiteSettings> updateSiteSettings({
    EntraSiteConfig? entra,
    String? wordpressSiteUrl,
    String? wordpressApiToken,
  }) async {
    if (entra != null) {
      await SiteSettingsStore.setEntra(
        tenantId: entra.tenantId,
        clientId: entra.clientId,
        redirectUri: entra.redirectUri,
      );
    }
    if (wordpressSiteUrl != null || wordpressApiToken != null) {
      final current = await SiteSettingsStore.wordpress();
      await SiteSettingsStore.setWordPress(
        siteUrl: wordpressSiteUrl ?? current.siteUrl,
        apiToken: wordpressApiToken ?? current.apiToken,
      );
    }
    return getSiteSettings();
  }

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
        activeAccounts: 1,
        newAccounts: 0,
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
        likes: _feed.where((p) => p.likedByMe).length,
        stories: (await getStories()).length,
        reviews: reviewsList.length,
        averageRating: double.parse(avgRating.toStringAsFixed(2)),
        moderationReportsFiled: _reports.length,
        moderationReportsUnresolved: _reports.where((r) => r.resolvedAt == null).length,
      ),
      applications: ApplicationStats(
        pending: _applications
            .where((a) =>
                a.status == ParticipationApplication.statusDetailFormSubmitted ||
                a.status == ParticipationApplication.statusUnderReview)
            .length,
        approved: _applications.where((a) => a.status == ParticipationApplication.statusApproved).length,
        rejected: _applications.where((a) => a.status == ParticipationApplication.statusRejected).length,
        byType: [
          for (final type in {'club', 'sport', 'service', 'career'})
            KindCount(
              kind: type,
              total: _applications.where((a) => a.targetType == type).length,
            ),
        ].where((k) => k.total > 0).toList(),
      ),
      notifications: const NotificationStats(total: 0, unread: 0),
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
        foodVenues: (await getFoodVenues()).length,
        directoryEntries: directory.length,
        mediaItems: 0,
      ),
    );
  }

  @override
  Future<List<CampusStory>> getStories() async {
    _stories.removeWhere((s) => s.isExpired);
    // Mock mode has no backend, so "seen" state is legitimately local —
    // unlike REST mode, there is no server truth to defer to here.
    final viewed = await SocialLocalPrefs.viewedStoryIds();
    return List.unmodifiable(_stories
        .map((s) => s.copyWith(viewedByMe: viewed.contains(s.id)))
        .toList());
  }

  @override
  Future<void> addStory({
    String? text,
    Uint8List? imageBytes,
    int? backgroundColorValue,
    Map<String, dynamic>? style,
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
        style: style,
        visibility: visibility,
      ),
    );
  }

  @override
  Future<void> deleteStory(String storyId) async {
    _stories.removeWhere((s) => s.id == storyId && s.authorId == _user.id);
  }

  @override
  Future<void> markStoryViewed(String storyId) async {
    await SocialLocalPrefs.markStoryViewed(storyId);
  }

  @override
  Future<List<StoryViewer>> getStoryViewers(String storyId) async => const [];

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
      String? mediaFileName,
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
  Future<FeedPost> toggleLike(String postId) async {
    final index = _feed.indexWhere((p) => p.id == postId);
    if (index == -1) {
      throw StateError('Post $postId is not in the mock feed.');
    }
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
    return _feed[index];
  }

  @override
  Future<FeedPost> addComment(String postId, String text) async {
    assertTextAllowed(text);
    final index = _feed.indexWhere((p) => p.id == postId);
    if (index == -1) {
      throw StateError('post not found');
    }
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
    return _feed[index];
  }

  @override
  Future<FeedPost> updatePost(String postId, {String? text, PostVisibility? visibility}) async {
    final index = _feed.indexWhere((p) => p.id == postId && p.authorId == _user.id);
    if (index == -1) throw Exception('Post not found or not owned by the current user.');
    if (text != null) assertTextAllowed(text);
    _feed[index] = _feed[index].copyWith(text: text, visibility: visibility);
    return _feed[index];
  }

  @override
  Future<void> deletePost(String postId) async {
    _feed.removeWhere((p) => p.id == postId && p.authorId == _user.id);
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
  Future<String> askGuide(String prompt,
      {List<({bool fromUser, String text})> history = const [],
      String? conversationId}) async {
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
            'kısmından "Ön Başvuru / Katıl" ile başvurabilirsin.';
      }
    }
    if (q.contains('menü') || q.contains('menu') || q.contains('yemek') || q.contains('garden')) {
      for (final venue in await getFoodVenues()) {
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

  @override
  Future<List<AskArucadConversation>> getAskConversations() => AskArucadStore.all();

  @override
  Future<AskArucadConversation?> getAskConversation(String id) async {
    final all = await AskArucadStore.all();
    for (final c in all) {
      if (c.id == id) return c;
    }
    return null;
  }

  @override
  Future<void> deleteAskConversation(String id) => AskArucadStore.delete(id);

  @override
  Future<PageSlice<FeedPost>> getPendingPosts({int page = 1, int perPage = 20}) async {
    final pending = _feed.where((p) => p.workflowStatus == 'pending_review').toList();
    return _pageOf(pending, page: page, perPage: perPage);
  }

  @override
  Future<void> approvePendingPost(String id) async {
    final index = _feed.indexWhere((p) => p.id == id);
    if (index == -1) return;
    final post = _feed[index];
    _feed[index] = FeedPost(
      id: post.id,
      authorId: post.authorId,
      name: post.name,
      text: post.text,
      meta: post.meta,
      likes: post.likes,
      likedByMe: post.likedByMe,
      imageUrl: post.imageUrl,
      imageBytes: post.imageBytes,
      comments: post.comments,
      visibility: post.visibility,
      kind: post.kind,
      postType: post.postType,
      courseTag: post.courseTag,
      locationTag: post.locationTag,
      official: post.official,
      isPinned: post.isPinned,
      workflowStatus: 'published',
    );
  }

  @override
  Future<void> rejectPendingPost(String id, {String? reviewNote}) async {
    _feed.removeWhere((p) => p.id == id);
  }

  @override
  Future<void> snapshotWordpressForms() async {
    throw StateError('WORDPRESS_NOT_CONFIGURED');
  }

  static double _distanceMeters(double lat1, double lng1, double lat2, double lng2) {
    const earth = 6371000.0;
    final phi1 = lat1 * math.pi / 180;
    final phi2 = lat2 * math.pi / 180;
    final dPhi = (lat2 - lat1) * math.pi / 180;
    final dLam = (lng2 - lng1) * math.pi / 180;
    final a = math.sin(dPhi / 2) * math.sin(dPhi / 2) +
        math.cos(phi1) * math.cos(phi2) * math.sin(dLam / 2) * math.sin(dLam / 2);
    return 2 * earth * math.asin(math.sqrt(a.clamp(0.0, 1.0)));
  }

  @override
  Future<CareerProfile> uploadCareerCv(Uint8List bytes, {required String fileName}) async {
    _careerProfile = CareerProfile(
      occupation: _careerProfile.occupation,
      expertise: _careerProfile.expertise,
      hasCv: true,
      cvFileName: fileName,
      lookingForInternships: _careerProfile.lookingForInternships,
      lookingForJobs: _careerProfile.lookingForJobs,
      updatedAt: DateTime.now(),
    );
    return _careerProfile;
  }

  @override
  Future<void> deleteCareerCv() async {
    _careerProfile = CareerProfile(
      occupation: _careerProfile.occupation,
      expertise: _careerProfile.expertise,
      lookingForInternships: _careerProfile.lookingForInternships,
      lookingForJobs: _careerProfile.lookingForJobs,
      updatedAt: DateTime.now(),
    );
  }

  @override
  Future<List<int>> downloadOwnCareerCv() async {
    if (!_careerProfile.hasCv) {
      throw ApiClientException('CV yok', code: 'CV_NOT_FOUND', statusCode: 404);
    }
    return '%PDF-1.4 mock\n%%EOF'.codeUnits;
  }

  @override
  Future<CareerOpportunity> getCareerOpportunity(String id) async =>
      _careerOpportunities.firstWhere((o) => o.id == id);

  @override
  Future<CareerApplication> applyToCareerOpportunity(String opportunityId) async {
    if (!_careerProfile.hasCv) {
      throw ApiClientException('CV required', code: 'CV_REQUIRED', statusCode: 400);
    }
    if (_careerApplications.any((a) =>
        a.opportunityId == opportunityId &&
        (a.status == 'pending' || a.status == 'reviewed' || a.status == 'shortlisted'))) {
      throw ApiClientException('Exists', code: 'APPLICATION_EXISTS', statusCode: 409);
    }
    final opp = _careerOpportunities.firstWhere((o) => o.id == opportunityId);
    final app = CareerApplication(
      id: 'capp-${DateTime.now().millisecondsSinceEpoch}',
      userId: _user.id,
      userName: _user.name,
      opportunityId: opportunityId,
      opportunityTitle: opp.title,
      hasCv: true,
      cvFileName: _careerProfile.cvFileName,
      status: 'pending',
      createdAt: DateTime.now(),
    );
    _careerApplications.add(app);
    return app;
  }

  @override
  Future<List<CareerApplication>> getMyCareerApplications() async =>
      List.of(_careerApplications);

  @override
  Future<List<CareerApplication>> getAdminCareerApplications(
          {String? status, String? opportunityId, String? q}) async =>
      _careerApplications
          .where((a) => status == null || a.status == status)
          .toList();

  @override
  Future<CareerApplication> updateCareerApplication(String id,
      {String? status, String? adminNotes}) async {
    final i = _careerApplications.indexWhere((a) => a.id == id);
    final old = _careerApplications[i];
    final next = CareerApplication(
      id: old.id,
      userId: old.userId,
      userName: old.userName,
      opportunityId: old.opportunityId,
      opportunityTitle: old.opportunityTitle,
      hasCv: old.hasCv,
      cvFileName: old.cvFileName,
      status: status ?? old.status,
      adminNotes: adminNotes ?? old.adminNotes,
      createdAt: old.createdAt,
    );
    _careerApplications[i] = next;
    return next;
  }

  @override
  Future<List<int>> downloadCareerApplicationCv(String id) async => const [];

  @override
  Future<PageSlice<CareerOpportunity>> getAdminCareerOpportunitiesPage(
          {int page = 1, int perPage = 20}) async =>
      PageSlice(
        items: _careerOpportunities,
        currentPage: page,
        perPage: perPage,
        total: _careerOpportunities.length,
        lastPage: 1,
      );

  @override
  Future<PageSlice<ConsultationOffering>> getConsultationsPage(
          {int page = 1, int perPage = 20}) async =>
      PageSlice(
        items: _consultations.where((c) => c.published).toList(),
        currentPage: page,
        perPage: perPage,
        total: _consultations.length,
        lastPage: 1,
      );

  @override
  Future<ConsultationOffering> getConsultation(String id) async =>
      _consultations.firstWhere((c) => c.id == id);

  @override
  Future<ConsultationApplication> applyToConsultation(String id, {String? notes}) async {
    if (_consultationApplications.any((a) =>
        a.consultationId == id &&
        (a.status == 'pending' || a.status == 'reviewed' || a.status == 'shortlisted'))) {
      throw ApiClientException('Exists', code: 'APPLICATION_EXISTS', statusCode: 409);
    }
    final c = _consultations.firstWhere((x) => x.id == id);
    final app = ConsultationApplication(
      id: 'consapp-${DateTime.now().millisecondsSinceEpoch}',
      userId: _user.id,
      userName: _user.name,
      consultationId: id,
      consultationTitle: c.title,
      status: 'pending',
      notes: notes,
      createdAt: DateTime.now(),
    );
    _consultationApplications.add(app);
    return app;
  }

  @override
  Future<List<ConsultationApplication>> getMyConsultationApplications() async =>
      List.of(_consultationApplications);

  @override
  Future<PageSlice<ConsultationOffering>> getAdminConsultationsPage(
          {int page = 1, int perPage = 20}) async =>
      PageSlice(
        items: _consultations,
        currentPage: page,
        perPage: perPage,
        total: _consultations.length,
        lastPage: 1,
      );

  @override
  Future<void> upsertConsultation(ConsultationOffering consultation) async {
    final i = _consultations.indexWhere((c) => c.id == consultation.id);
    if (i >= 0) {
      _consultations[i] = consultation;
    } else {
      _consultations.insert(0, consultation);
    }
  }

  @override
  Future<void> deleteConsultation(String id) async {
    _consultations.removeWhere((c) => c.id == id);
  }

  @override
  Future<List<ConsultationApplication>> getAdminConsultationApplications(
          {String? status, String? q}) async =>
      _consultationApplications;

  @override
  Future<ConsultationApplication> updateConsultationApplication(String id,
      {String? status, String? adminNotes}) async {
    final i = _consultationApplications.indexWhere((a) => a.id == id);
    final old = _consultationApplications[i];
    final next = ConsultationApplication(
      id: old.id,
      userId: old.userId,
      userName: old.userName,
      consultationId: old.consultationId,
      consultationTitle: old.consultationTitle,
      status: status ?? old.status,
      notes: old.notes,
      adminNotes: adminNotes ?? old.adminNotes,
      createdAt: old.createdAt,
    );
    _consultationApplications[i] = next;
    return next;
  }

  @override
  Future<AppointmentBooking> getAppointment(String id) async =>
      _appointments.firstWhere((a) => a.id == id);

  @override
  Future<AppointmentBooking> updateAdminAppointment(String id,
      {String? status, String? adminNotes, String? staffProfileId}) async {
    final i = _appointments.indexWhere((a) => a.id == id);
    final old = _appointments[i];
    final next = AppointmentBooking(
      id: old.id,
      staffProfileId: staffProfileId ?? old.staffProfileId,
      staffName: old.staffName,
      studentName: old.studentName,
      date: old.date,
      startTime: old.startTime,
      endTime: old.endTime,
      status: status ?? old.status,
      subject: old.subject,
      notes: old.notes,
      adminNotes: adminNotes ?? old.adminNotes,
      createdAt: old.createdAt,
    );
    _appointments[i] = next;
    return next;
  }

  @override
  Future<FeedPost> pinPost(String postId) async {
    final i = _feed.indexWhere((p) => p.id == postId);
    _feed[i] = FeedPost(
      id: _feed[i].id,
      authorId: _feed[i].authorId,
      name: _feed[i].name,
      text: _feed[i].text,
      meta: _feed[i].meta,
      likes: _feed[i].likes,
      likedByMe: _feed[i].likedByMe,
      imageUrl: _feed[i].imageUrl,
      comments: _feed[i].comments,
      official: _feed[i].official,
      isPinned: true,
    );
    return _feed[i];
  }

  @override
  Future<FeedPost> unpinPost(String postId) async {
    final i = _feed.indexWhere((p) => p.id == postId);
    _feed[i] = FeedPost(
      id: _feed[i].id,
      authorId: _feed[i].authorId,
      name: _feed[i].name,
      text: _feed[i].text,
      meta: _feed[i].meta,
      likes: _feed[i].likes,
      likedByMe: _feed[i].likedByMe,
      imageUrl: _feed[i].imageUrl,
      comments: _feed[i].comments,
      official: _feed[i].official,
    );
    return _feed[i];
  }

  @override
  Future<FeedPost> createOfficialPost(String text, {String? imageUrl, Uint8List? imageBytes}) async {
    final post = FeedPost(
      id: 'post-${DateTime.now().millisecondsSinceEpoch}',
      authorId: _user.id,
      name: _user.name,
      text: text,
      meta: 'resmi duyuru',
      likes: 0,
      imageUrl: imageUrl,
      imageBytes: imageBytes,
      official: true,
    );
    _feed.insert(0, post);
    return post;
  }

  @override
  Future<List<CampusUser>> getFriends() async {
    final names = await SocialGraphStore.following();
    return names
        .map((name) => CampusUser(
              id: name,
              name: name,
              role: 'student',
              level: 1,
              xp: 0,
              places: 0,
              events: 0,
              memories: 0,
              interests: const [],
            ))
        .toList();
  }

  @override
  Future<CampusUser?> getSocialUser(String id) async => null;
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
