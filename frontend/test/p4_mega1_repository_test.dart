import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/media_library_store.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/core/services/rest_campus_repository.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  group('MockCampusRepository P4 mega-1', () {
    test('achievements unlock from check-in and stay unlocked', () async {
      final repo = MockCampusRepository();
      final places = await repo.getPlaces();

      var locked = await repo.getAchievements();
      expect(locked.where((a) => a.id == 'ach-first-checkin').single.unlocked, isFalse);

      final place = places.first;
      await repo.checkIn(place.id, latitude: place.lat, longitude: place.lng);
      final unlocked = await repo.getAchievements();
      expect(unlocked.where((a) => a.id == 'ach-first-checkin').single.unlocked, isTrue);

      // Cooldown: second check-in must not throw away the unlock, but may
      // be rejected as ALREADY_CHECKED_IN.
      try {
        await repo.checkIn(place.id, latitude: place.lat, longitude: place.lng);
      } on ApiClientException catch (e) {
        expect(e.code, 'ALREADY_CHECKED_IN');
      }
      expect(
        (await repo.getAchievements()).where((a) => a.unlocked).length,
        unlocked.where((a) => a.unlocked).length,
      );
    });

    test('personal gallery is separate from admin media library', () async {
      final repo = MockCampusRepository();
      final mine = await repo.uploadMyMedia(Uint8List.fromList([1, 2, 3]), fileName: 'me.jpg');
      expect((await repo.getMyMediaPage()).items.map((m) => m.id), contains(mine.id));
      expect((await repo.getMedia()).map((m) => m.id), isNot(contains(mine.id)));
      expect(await MediaLibraryStore.items(), isEmpty);

      await repo.deleteMyMedia(mine.id);
      expect((await repo.getMyMediaPage()).items, isEmpty);
    });

    test('bandabuliya events filter and career profile round-trip', () async {
      final repo = MockCampusRepository();
      final banda = await repo.getEvents(category: 'Bandabuliya');
      expect(banda, isNotEmpty);
      expect(banda.every((e) => e.category == 'Bandabuliya'), isTrue);

      final communities = await repo.getClubs(category: 'Community');
      expect(communities.every((c) => c.category == 'Community'), isTrue);

      final updated = await repo.updateCareerProfile(
        headline: 'Designer',
        lookingForInternships: true,
      );
      expect(updated.headline, 'Designer');
      expect((await repo.getCareerProfile()).lookingForInternships, isTrue);
      expect((await repo.getCareerOpportunitiesPage()).items, isNotEmpty);
    });
  });

  test('RestCampusRepository hits P4 mega-1 endpoints', () async {
    final paths = <String>[];
    final mock = MockClient((request) async {
      paths.add('${request.method} ${request.url.path}');
      if (request.url.path.endsWith('/me/achievements')) {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'ach-first-checkin',
                'title': 'First Step',
                'subtitle': 'x',
                'triggerKind': 'checkin_count',
                'threshold': 1,
                'unlocked': false,
                'unlockedAt': null,
              }
            ],
            'meta': {},
            'error': null,
          }),
          200,
          headers: {'content-type': 'application/json; charset=utf-8'},
        );
      }
      if (request.url.path.endsWith('/media/mine')) {
        if (request.method == 'GET') {
          return http.Response(
            jsonEncode({
              'data': [],
              'meta': {
                'pagination': {
                  'currentPage': 1,
                  'perPage': 20,
                  'total': 0,
                  'lastPage': 1,
                }
              },
              'error': null,
            }),
            200,
          );
        }
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'media-p',
              'url': '/storage/media/p.jpg',
              'fileName': 'p.jpg',
              'uploadedAt': '2026-08-25T12:00:00.000Z',
              'uploadedBy': 'Ezgi',
              'usedIn': <String>[],
            },
            'meta': {},
            'error': null,
          }),
          201,
        );
      }
      if (request.url.path.contains('/career/opportunities')) {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'c1',
                'title': 'Staj',
                'kind': 'internship',
                'organization': 'ARUCAD',
                'published': true,
              }
            ],
            'meta': {
              'pagination': {
                'currentPage': 1,
                'perPage': 20,
                'total': 1,
                'lastPage': 1,
              }
            },
            'error': null,
          }),
          200,
        );
      }
      if (request.url.path.endsWith('/me/career-profile')) {
        return http.Response(
          jsonEncode({
            'data': {
              'headline': null,
              'cvUrl': null,
              'lookingForInternships': false,
              'lookingForJobs': false,
              'updatedAt': null,
            },
            'meta': {},
            'error': null,
          }),
          200,
        );
      }
      if (request.url.path.endsWith('/events') &&
          request.url.queryParameters['category'] == 'Bandabuliya') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'e1',
                'title': 'Banda',
                'time': '18:00',
                'placeName': 'Bandabuliya',
                'category': 'Bandabuliya',
                'attendees': 1,
                'xp': 10,
              }
            ],
            'meta': {},
            'error': null,
          }),
          200,
        );
      }
      if (request.url.path.endsWith('/clubs') &&
          request.url.queryParameters['category'] == 'Community') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'club-charity',
                'name': 'Charity',
                'category': 'Community',
                'description': 'x',
              }
            ],
            'meta': {},
            'error': null,
          }),
          200,
        );
      }
      return http.Response(jsonEncode({'data': {}, 'meta': {}, 'error': null}), 200);
    });

    final repo = RestCampusRepository(
      client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
    );

    expect((await repo.getAchievements()).single.id, 'ach-first-checkin');
    expect((await repo.getMyMediaPage()).total, 0);
    await repo.uploadMyMedia(Uint8List.fromList([1]), fileName: 'p.jpg');
    expect((await repo.getCareerOpportunitiesPage()).items.single.title, 'Staj');
    expect((await repo.getCareerProfile()).lookingForInternships, isFalse);
    expect((await repo.getEvents(category: 'Bandabuliya')).single.category, 'Bandabuliya');
    expect((await repo.getClubs(category: 'Community')).single.category, 'Community');

    expect(paths, contains('GET /api/v1/me/achievements'));
    expect(paths, contains('GET /api/v1/media/mine'));
    expect(paths, contains('POST /api/v1/media/mine'));
    expect(paths, contains('GET /api/v1/career/opportunities'));
  });
}
