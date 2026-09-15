import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/config/campus_sites.dart';
import 'package:arucad_campus_prototype/core/config/place_catalog.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:arucad_campus_prototype/core/config/poi_config.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/directions_result.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/features/home/campus_live_map.dart';
import 'package:arucad_campus_prototype/features/home/home_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

CampusPlace _place({
  required String id,
  required String name,
  String category = 'Administration',
  double lat = 35.337305,
  double lng = 33.321303,
  String description = '',
}) =>
    CampusPlace(
      id: id,
      name: name,
      category: category,
      lat: lat,
      lng: lng,
      description: description,
      distance: '',
      density: 'quiet',
      street: '',
      tourUrl: null,
      accessible: true,
      photos: 0,
      rating: 0,
    );

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });
  test('place list collapses Garden names with invisible or repeated spaces',
      () {
    final places = uniqueCampusPlaces([
      _place(id: 'place-garden', name: 'The Garden'),
      _place(id: 'the-garden', name: 'The\u00a0 Garden\u200b'),
      _place(id: 'garden-copy', name: ' THE GARDEN '),
    ]);
    expect(places, hasLength(1));
    expect(places.single.id, 'the-garden');
  });
  test('placeLineArt maps campus identity icons', () {
    expect(placeLineArt('Art Rooms')?.asset, contains('art-rooms'));
    expect(placeLineArt('Art Rooms')?.color, ArucadColors.campusGreen);
    expect(placeLineArt('The Kiss')?.color, ArucadColors.orange);
    expect(placeLineArt('The Garden')?.color, ArucadColors.lavender);
    expect(placeLineArt('Titan')?.asset, contains('titan'));
  });

  test('campusMapPlaces keeps all 19 SQL names even from an empty API list',
      () {
    final pins = campusMapPlaces(const []);
    final names = pins.map((p) => p.name).toSet();
    for (final name in sqlCampusPoiNames) {
      expect(names, contains(name), reason: name);
    }
  });

  test(
      'campusMapPlaces drops legacy place-* aliases and the workshops cluster duplicate',
      () {
    final pins = campusMapPlaces([
      _place(
          id: 'place-library',
          name: 'Meditation',
          lat: 35.337754,
          lng: 33.321358),
      _place(
          id: 'meditation', name: 'Meditation', lat: 35.337754, lng: 33.321358),
      _place(
          id: 'arucad-workshops',
          name: 'ARUCAD Workshops',
          lat: 35.333593,
          lng: 33.330680),
    ]);
    expect(pins.where((p) => p.name == 'Meditation').length, 1);
    expect(pins.any((p) => p.id.startsWith('place-')), isFalse);
    expect(pins.any((p) => p.name == 'ARUCAD Workshops'), isFalse);
  });

  test('spreadOverlappingMapPins nudges shared workshop coordinates', () {
    final pins = spreadOverlappingMapPins([
      _place(
          id: 'age-of-bronze',
          name: 'Age of Bronze',
          lat: 35.333593,
          lng: 33.330680),
      _place(
          id: 'art-rooms', name: 'Art Rooms', lat: 35.333593, lng: 33.330680),
      _place(
          id: 'iris-atelier',
          name: 'Iris (Atelier Building)',
          lat: 35.333593,
          lng: 33.330680),
    ]);
    expect(pins.length, 3);
    final lngs = pins.map((p) => p.display.lng).toSet();
    expect(lngs.length, 3);
    expect(pins.every((p) => p.place.lng == 33.330680), isTrue);
  });

  test('mainCampusCameraExtent stays on Girne and excludes Lefkoşa', () {
    final extent = mainCampusCameraExtent([
      _place(id: 'rodin', name: 'Rodin'),
      _place(id: 'eve', name: 'Eve', lat: 35.337529, lng: 33.321303),
      _place(id: 'titan', name: 'Titan', lat: 35.337170, lng: 33.321633),
      _place(id: 'daniele', name: 'Daniele', lat: 35.337772, lng: 33.321688),
      _place(id: 'minotaur', name: 'Minotaur', lat: 35.337844, lng: 33.321270),
      _place(id: 'the-kiss', name: 'The Kiss', lat: 35.337799, lng: 33.321082),
      _place(
          id: 'nicosia-bandabuliya',
          name: 'Nicosia Bandabuliya Campus',
          lat: 35.175513,
          lng: 33.365029),
    ]);
    expect(extent.length, greaterThanOrEqualTo(6));
    expect(extent.every((p) => p.lat > 35.33), isTrue);
  });

  testWidgets('PlaceInfoSheet shows about text and can start navigation',
      (tester) async {
    var started = false;
    TravelMode? startedWith;
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: PlaceInfoSheet(
          poi: const Poi(
            name: 'Rodin',
            category: 'Administration',
            lat: 35.337305,
            lng: 33.321303,
            note: 'Rektörlük burada.',
          ),
          place: _place(
            id: 'rodin',
            name: 'Rodin',
            description:
                'Bina, ARUCAD adını taşıyan Fransız heykeltıraş Auguste Rodin\'e ithaf edilmiş.',
          ),
          events: const [],
          visibility: CampusVisibility.friends,
          onNavigate: (mode) {
            started = true;
            startedWith = mode;
          },
          onDetails: () {},
        ),
      ),
    ));

    expect(find.textContaining('Auguste Rodin'), findsOneWidget);
    expect(find.text('Detay'), findsOneWidget);

    // The sheet now asks how you are travelling instead of assuming you are
    // walking: picking the mode here means someone heading for the shuttle
    // does not have to open a walking route first and then switch.
    expect(find.text('Nasıl gitmek istersin?'), findsOneWidget);
    for (final label in ['Yürüyerek', 'Araba', 'Otobüs']) {
      expect(find.text(label), findsOneWidget,
          reason: '$label should be offered straight from the map pin.');
    }

    await tester.ensureVisible(find.text('Otobüs'));
    await tester.tap(find.text('Otobüs'));
    expect(started, isTrue);
    expect(startedWith, TravelMode.transit,
        reason: 'The chosen mode must reach the navigation screen.');
  });

  testWidgets(
      'PlaceInfoSheet shows an honest empty state when no one has checked in',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: PlaceInfoSheet(
          poi: const Poi(
            name: 'Rodin',
            category: 'Administration',
            lat: 35.337305,
            lng: 33.321303,
          ),
          place: _place(id: 'rodin', name: 'Rodin'),
          events: const [],
          visibility: CampusVisibility.friends,
          onNavigate: (_) {},
          onDetails: () {},
        ),
      ),
    ));

    expect(find.text('Henüz check-in yok'), findsOneWidget);
  });

  testWidgets('PlaceInfoSheet renders real check-in entries from the backend',
      (tester) async {
    final place = _place(id: 'rodin', name: 'Rodin').copyWith();
    final withEntries = CampusPlace(
      id: place.id,
      name: place.name,
      category: place.category,
      lat: place.lat,
      lng: place.lng,
      description: place.description,
      distance: place.distance,
      density: place.density,
      street: place.street,
      tourUrl: place.tourUrl,
      accessible: place.accessible,
      photos: place.photos,
      rating: place.rating,
      recentCheckinEntries: [
        CampusCheckinEntry(initial: 'Z.', checkedInAt: DateTime.now()),
      ],
    );

    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: PlaceInfoSheet(
          poi: const Poi(
            name: 'Rodin',
            category: 'Administration',
            lat: 35.337305,
            lng: 33.321303,
          ),
          place: withEntries,
          events: const [],
          visibility: CampusVisibility.friends,
          onNavigate: (_) {},
          onDetails: () {},
        ),
      ),
    ));

    expect(find.textContaining('Z.'), findsOneWidget);
    expect(find.text('Henüz check-in yok'), findsNothing);
  });

  testWidgets(
      'PlaceInfoSheet loads real workshop equipment and collaboration posts',
      (tester) async {
    final repository = MockCampusRepository();
    final place = _place(id: 'w1', name: 'Carpentry Studio', category: 'Workshop');
    await repository.upsertWorkshopEquipment('w1',
        name: 'Lazer Kesici', available: false);
    await repository.addCollaborationPost('w1', 'Malzeme takası: kil ⇄ ahşap');

    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: PlaceInfoSheet(
          poi: Poi(
            name: place.name,
            category: place.category,
            lat: place.lat,
            lng: place.lng,
          ),
          place: place,
          repository: repository,
          events: const [],
          visibility: CampusVisibility.friends,
          onNavigate: (_) {},
          onDetails: () {},
        ),
      ),
    ));
    await tester.pump();

    expect(find.text('Lazer Kesici'), findsOneWidget);
    expect(find.text('Dolu'), findsOneWidget);
    expect(find.text('Malzeme takası: kil ⇄ ahşap'), findsOneWidget);
  });

  testWidgets(
      'PlaceInfoSheet shows honest empty workshop states with no data',
      (tester) async {
    final repository = MockCampusRepository();
    final place = _place(id: 'w2', name: 'Empty Studio', category: 'Workshop');

    await tester.pumpWidget(MaterialApp(
      home: Scaffold(
        body: PlaceInfoSheet(
          poi: Poi(
            name: place.name,
            category: place.category,
            lat: place.lat,
            lng: place.lng,
          ),
          place: place,
          repository: repository,
          events: const [],
          visibility: CampusVisibility.friends,
          onNavigate: (_) {},
          onDetails: () {},
        ),
      ),
    ));
    await tester.pump();

    expect(find.text('Ekipman bilgisi henüz eklenmedi'), findsOneWidget);
    expect(find.text('Henüz ilan yok'), findsOneWidget);
  });

  test('poiFromPlace copies description into the about note', () {
    final poi = poiFromPlace(_place(
      id: 'titan',
      name: 'Titan',
      description: 'Öğrenci İşleri burada.',
    ));
    expect(poi.note, 'Öğrenci İşleri burada.');
    expect(poi, isA<Poi>());
    expect(GeoPoint(poi.lat, poi.lng), const GeoPoint(35.337305, 33.321303));
  });

  test('resolvePlaceTourUrl opens the real 3DVista exports', () {
    expect(
      resolvePlaceTourUrl(lat: 35.337395, lng: 33.321358, stored: null),
      contains('vista_export/Main'),
    );
    expect(
      resolvePlaceTourUrl(
        lat: 35.337395,
        lng: 33.321358,
        stored: 'https://360.arucad.edu.tr/tour?campusId=main',
      ),
      contains('vista_export/Main'),
    );
    expect(
      resolvePlaceTourUrl(
        lat: 35.337395,
        lng: 33.321358,
        stored: 'https://360.arucad.edu.tr/custom/path/index.htm',
      ),
      'https://360.arucad.edu.tr/custom/path/index.htm',
    );
    expect(
      resolvePlaceTourUrl(lat: 35.175513, lng: 33.365029, stored: null),
      contains('vista_export/Bandabuliya'),
    );
    expect(
      resolvePlaceTourUrl(lat: 35.333593, lng: 33.330680, stored: null),
      contains('vista_export/Atelier'),
    );
  });

  testWidgets('full-screen map route shows the campus map chrome',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: CampusMapFullScreen(
        places: [
          _place(
            id: 'rodin',
            name: 'Rodin',
            description: 'Rektörlük burada.',
          ),
        ],
        events: const [],
        repository: MockCampusRepository(),
        mapProvider: _NoopMap(),
        analyticsTracker: _NoopAnalytics(),
        onOpenGalatea: () {},
      ),
    ));
    await tester.pump();
    expect(find.text('ARUCAD Social Map'), findsOneWidget);
  });

  testWidgets('Home Haritayı Aç opens the full-screen map', (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: AppLocale(
        language: AppLanguage.tr,
        child: Scaffold(
          body: HomeScreen(
            user: const CampusUser(
              id: 'u1',
              name: 'Test',
              role: 'student',
              level: 1,
              xp: 0,
              places: 0,
              events: 0,
              memories: 0,
              interests: [],
            ),
            repository: MockCampusRepository(),
            mapProvider: _NoopMap(),
            analyticsTracker: _NoopAnalytics(),
            onExplore: () {},
            onQuests: () {},
            onAI: () {},
            onSocial: () {},
            onLogout: () {},
          ),
        ),
      ),
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 200));
    if (find.text('Hızlı Anket').evaluate().isNotEmpty) {
      await tester.tapAt(const Offset(8, 8));
      await tester.pump(const Duration(milliseconds: 300));
    }
    // Personalised/social sections intentionally precede the large map on
    // phones, so the lazily-built map header may not exist in the first
    // viewport yet. Scroll the Home list until it is materialised.
    for (var i = 0;
        i < 6 && find.text('Haritayı Aç').evaluate().isEmpty;
        i++) {
      await tester.drag(
          find.byType(ListView).first, const Offset(0, -420));
      await tester.pump();
    }
    expect(find.text('Haritayı Aç'), findsOneWidget);
    await tester.tap(find.text('Haritayı Aç'));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));
    expect(find.text('ARUCAD Social Map'), findsOneWidget);
  });

  testWidgets('Home header brand fits a phone and has no language toggle',
      (tester) async {
    await tester.binding.setSurfaceSize(const Size(360, 740));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(MaterialApp(
      home: AppLocale(
        language: AppLanguage.tr,
        child: Scaffold(
          body: HomeScreen(
            user: const CampusUser(
              id: 'u1',
              name: 'Test',
              role: 'student',
              level: 1,
              xp: 1280,
              places: 0,
              events: 0,
              memories: 0,
              interests: [],
            ),
            repository: MockCampusRepository(),
            mapProvider: _NoopMap(),
            analyticsTracker: _NoopAnalytics(),
            onExplore: () {},
            onQuests: () {},
            onAI: () {},
            onSocial: () {},
            onLogout: () {},
          ),
        ),
      ),
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 200));
    if (find.text('Hızlı Anket').evaluate().isNotEmpty) {
      await tester.tapAt(const Offset(8, 8));
      await tester.pump(const Duration(milliseconds: 300));
    }
    expect(find.byType(BrandMark), findsOneWidget);
    // Notifications deliberately no longer live on Home — they moved to the
    // Explore and Social headers, where students go looking for them.
    expect(find.byIcon(Icons.notifications_outlined), findsNothing);
    expect(find.byIcon(Icons.logout), findsOneWidget);
    expect(find.text('EN'), findsNothing);
    expect(find.text('RU'), findsNothing);
    expect(tester.takeException(), isNull);
  });
}

class _NoopMap implements MapProvider {
  @override
  Future<void> startRoute(
      {required String destination, bool accessibleOnly = false}) async {}

  @override
  Future<void> openTour(String tourUrl) async {}
}

class _NoopAnalytics implements AnalyticsTracker {
  @override
  void track(String event, [Map<String, Object?> properties = const {}]) {}
}
