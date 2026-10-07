import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/config/poi_config.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/core/services/rest_campus_repository.dart';
import 'package:arucad_campus_prototype/features/home/campus_live_map.dart';

/// The map's crowd numbers come from where people actually are (live
/// location pings), not from the handful of students who deliberately
/// check in — and the shuttle panel on the map is a timetable, not a
/// countdown to a vehicle nobody is tracking.

CampusPlace _place({
  required String id,
  required String name,
  int recentCheckins = 0,
}) =>
    CampusPlace(
      id: id,
      name: name,
      category: 'Study',
      lat: 35.337305,
      lng: 33.321303,
      description: '',
      distance: '',
      density: 'quiet',
      street: '',
      tourUrl: null,
      accessible: true,
      photos: 0,
      rating: 0,
      recentCheckins: recentCheckins,
    );

Future<void> _pumpInfoSheet(
  WidgetTester tester, {
  required CampusLiveCrowd crowd,
  CampusVisibility visibility = CampusVisibility.friends,
}) async {
  await tester.pumpWidget(MaterialApp(
    home: Scaffold(
      body: MapInfoSheet(
        crowd: crowd,
        visibility: visibility,
        repository: MockCampusRepository(),
        onOpenShuttle: () {},
        onChangeVisibility: () {},
      ),
    ),
  ));
  await tester.pump();
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  group('CampusLiveCrowd', () {
    test('parses the live presence payload', () {
      final crowd = CampusLiveCrowd.fromJson({
        'places': [
          {
            'placeId': 'library',
            'name': 'Library',
            'category': 'Study',
            'lat': 35.33715,
            'lng': 33.32135,
            'count': 12,
          },
        ],
        'total': 18,
        'windowMinutes': 10,
      });

      expect(crowd.total, 18);
      expect(crowd.windowMinutes, 10);
      expect(crowd.places.single.placeId, 'library');
      expect(crowd.places.single.count, 12);
      expect(crowd.isEmpty, isFalse);
    });

    test('an absent payload is an honest empty snapshot, not a zeroed one', () {
      final crowd = CampusLiveCrowd.fromJson(const {});
      expect(crowd.isEmpty, isTrue);
      expect(crowd.places, isEmpty);
    });
  });

  group('map information panel', () {
    testWidgets('lists the busiest places with their real head counts',
        (tester) async {
      await _pumpInfoSheet(
        tester,
        crowd: const CampusLiveCrowd(
          total: 21,
          windowMinutes: 10,
          places: [
            LivePlaceCrowd(
                placeId: 'gallery',
                name: 'Gallery',
                category: 'Culture',
                lat: 35.3378,
                lng: 33.3221,
                count: 14),
            LivePlaceCrowd(
                placeId: 'library',
                name: 'Library',
                category: 'Study',
                lat: 35.33715,
                lng: 33.32135,
                count: 7),
          ],
        ),
      );

      expect(find.text('Şu an en kalabalık'), findsOneWidget);
      expect(find.text('Kampüste 21 kişi'), findsOneWidget);
      expect(find.text('Gallery'), findsOneWidget);
      expect(find.text('14 kişi'), findsOneWidget);
      expect(find.text('Library'), findsOneWidget);
      expect(find.text('7 kişi'), findsOneWidget);
      expect(find.text('Son 10 dakika'), findsOneWidget);
    });

    testWidgets('says nobody is sharing rather than inventing a crowd',
        (tester) async {
      await _pumpInfoSheet(tester, crowd: const CampusLiveCrowd());

      expect(find.text('Şu anda konumunu paylaşan kimse yok.'), findsOneWidget);
      expect(find.text('Kampüste 0 kişi'), findsOneWidget);
    });

    testWidgets('tells a hidden student they are not part of the count',
        (tester) async {
      await _pumpInfoSheet(
        tester,
        crowd: const CampusLiveCrowd(total: 3),
        visibility: CampusVisibility.ghost,
      );

      expect(
          find.text('Gizli moddasın — sayıma katılmıyorsun.'), findsOneWidget);
    });

    testWidgets('shuttle lines show only the route and stops', (tester) async {
      await _pumpInfoSheet(tester, crowd: const CampusLiveCrowd());
      // The bundled route renders immediately; the repository call only
      // replaces it with the identical backend copy.
      await tester.pump();

      expect(find.text('Lefkoşa Servisi'), findsOneWidget);
      // The roadmap: the stops the line actually calls at.
      expect(find.textContaining('Gönyeli Kavşağı'), findsWidgets);

      // No timetable, travel-time estimate or countdown belongs on the map.
      for (final banned in [
        '07:00',
        'dk sonra',
        'sa sonra',
        'şimdi',
        'Sıradaki'
      ]) {
        expect(find.textContaining(banned), findsNothing,
            reason: 'The map panel shows the shuttle route only.');
      }
    });
  });

  group('place info sheet', () {
    testWidgets('prefers the live head count over the check-in count',
        (tester) async {
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: PlaceInfoSheet(
            poi: const Poi(
              name: 'Library',
              category: 'Study',
              lat: 35.33715,
              lng: 33.32135,
            ),
            place: _place(id: 'library', name: 'Library', recentCheckins: 2),
            events: const [],
            visibility: CampusVisibility.friends,
            liveCount: 9,
            onNavigate: (_) {},
            onDetails: () {},
          ),
        ),
      ));

      expect(find.text('Burada 9 kişi'), findsOneWidget);
      expect(find.text('Burada 2 kişi'), findsNothing);
    });

    testWidgets(
        'falls back to check-ins when this place is not in the '
        'live snapshot', (tester) async {
      await tester.pumpWidget(MaterialApp(
        home: Scaffold(
          body: PlaceInfoSheet(
            poi: const Poi(
              name: 'Library',
              category: 'Study',
              lat: 35.33715,
              lng: 33.32135,
            ),
            place: _place(id: 'library', name: 'Library', recentCheckins: 2),
            events: const [],
            visibility: CampusVisibility.friends,
            onNavigate: (_) {},
            onDetails: () {},
          ),
        ),
      ));

      expect(find.text('Burada 2 kişi'), findsOneWidget);
    });
  });

  group('presence repository', () {
    test('RestCampusRepository pings, forgets and reads live crowd counts',
        () async {
      final calls = <http.Request>[];
      final mock = MockClient((request) async {
        calls.add(request);
        if (request.method == 'POST' &&
            request.url.path.endsWith('/presence/ping')) {
          return http.Response(
            jsonEncode({
              'data': {
                'placeId': 'library',
                'placeName': 'Library',
                'sharing': true
              },
              'meta': {},
              'error': null,
            }),
            200,
          );
        }
        if (request.method == 'POST' &&
            request.url.path.endsWith('/presence/forget')) {
          return http.Response(
            jsonEncode({
              'data': {'placeId': null, 'sharing': false},
              'meta': {},
              'error': null,
            }),
            200,
          );
        }
        if (request.method == 'GET' &&
            request.url.path.endsWith('/presence/live')) {
          return http.Response(
            jsonEncode({
              'data': {
                'places': [
                  {
                    'placeId': 'library',
                    'name': 'Library',
                    'category': 'Study',
                    'lat': 35.33715,
                    'lng': 33.32135,
                    'count': 4,
                  },
                ],
                'total': 4,
                'windowMinutes': 10,
              },
              'meta': {},
              'error': null,
            }),
            200,
          );
        }
        return http.Response(
            jsonEncode({'data': {}, 'meta': {}, 'error': null}), 404);
      });

      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      final resolved =
          await repo.pingPresence(latitude: 35.33715, longitude: 33.32135);
      expect(resolved, 'library');

      final body = jsonDecode(calls.first.body) as Map<String, dynamic>;
      expect(body['latitude'], 35.33715);
      expect(body['longitude'], 33.32135);

      await repo.forgetPresence();
      final crowd = await repo.getLiveCrowd();
      expect(crowd.total, 4);
      expect(crowd.places.single.name, 'Library');

      expect(
          calls.map((r) => r.url.path).toList(),
          containsAll([
            '/api/v1/presence/ping',
            '/api/v1/presence/forget',
            '/api/v1/presence/live',
          ]));
    });

    test('Mock mode reports no live crowd rather than a fabricated one',
        () async {
      final repo = MockCampusRepository();

      expect(
        await repo.pingPresence(latitude: 35.33715, longitude: 33.32135),
        isNull,
      );
      expect((await repo.getLiveCrowd()).isEmpty, isTrue);
    });
  });
}
