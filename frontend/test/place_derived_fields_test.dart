import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/core/services/rest_campus_repository.dart';

Map<String, dynamic> _envelope(dynamic data) => {
      'data': data,
      'meta': {'request_id': 'req-test'},
      'error': null,
    };

Map<String, dynamic> _placeJson({
  String density = 'quiet',
  double rating = 0,
  String? coverUrl,
  int recentCheckins = 0,
  int totalCheckins = 0,
}) =>
    {
      'id': 'atelier',
      'name': 'Atelier',
      'category': 'Studio',
      'lat': 1.0,
      'lng': 1.0,
      'description': '',
      'distance': '',
      'density': density,
      'street': '',
      'tourUrl': null,
      'accessible': true,
      'photos': 0,
      'rating': rating,
      'coverUrl': coverUrl,
      'recentCheckins': recentCheckins,
      'totalCheckins': totalCheckins,
    };

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  group('MockCampusRepository place derived fields', () {
    test('cover persists and a check-in bumps recentCheckins/density', () async {
      final repo = MockCampusRepository();
      final before = (await repo.getPlaces()).firstWhere((p) => p.id == 'meditation');
      expect(before.coverUrl, isNull);
      expect(before.recentCheckins, 0);

      await repo.setPlaceCover('meditation', 'https://cdn.example/a.jpg');
      await repo.checkIn('meditation', latitude: before.lat, longitude: before.lng);

      final after = (await repo.getPlaces()).firstWhere((p) => p.id == 'meditation');
      expect(after.coverUrl, 'https://cdn.example/a.jpg');
      expect(after.recentCheckins, 1);
      expect(after.density, 'quiet');
    });

    test('rating is the average of stored reviews', () async {
      final repo = MockCampusRepository();
      final garden = (await repo.getPlaces()).firstWhere((p) => p.id == 'the-garden');
      expect(garden.rating, 5.0);
    });
  });

  group('RestCampusRepository place fields', () {
    test('getPlaces parses coverUrl, recentCheckins, derived density/rating', () async {
      final mock = MockClient((request) async => http.Response(
            jsonEncode(_envelope([
              _placeJson(
                density: 'busy',
                rating: 4.5,
                coverUrl: 'https://cdn.example/a.jpg',
                recentCheckins: 8,
                totalCheckins: 42,
              ),
            ])),
            200,
          ));
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      final places = await repo.getPlaces();
      expect(places.single.coverUrl, 'https://cdn.example/a.jpg');
      expect(places.single.recentCheckins, 8);
      expect(places.single.totalCheckins, 42);
      expect(places.single.density, 'busy');
      expect(places.single.rating, 4.5);
    });

    test('setPlaceCover posts url to /places/{id}/cover', () async {
      http.Request? seen;
      final mock = MockClient((request) async {
        seen = request;
        return http.Response(jsonEncode(_envelope(_placeJson(coverUrl: 'https://x'))), 200);
      });
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      await repo.setPlaceCover('atelier', 'https://x');

      expect(seen!.method, 'POST');
      expect(seen!.url.path, '/api/v1/places/atelier/cover');
      expect(jsonDecode(seen!.body), {'url': 'https://x'});
    });
  });
}
