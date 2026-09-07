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

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  group('MockCampusRepository club membership', () {
    test('starts with no joined clubs, join adds, leave removes', () async {
      final repo = MockCampusRepository();

      expect(await repo.getJoinedClubIds(), isEmpty);

      await repo.joinClub('club-1');
      expect(await repo.getJoinedClubIds(), {'club-1'});

      await repo.leaveClub('club-1');
      expect(await repo.getJoinedClubIds(), isEmpty);
    });

    test('joining twice does not error and stays joined', () async {
      final repo = MockCampusRepository();

      await repo.joinClub('club-1');
      await repo.joinClub('club-1');

      expect(await repo.getJoinedClubIds(), {'club-1'});
    });
  });

  group('RestCampusRepository club membership', () {
    test('getJoinedClubIds hits GET /club-memberships', () async {
      http.Request? seen;
      final mock = MockClient((request) async {
        seen = request;
        return http.Response(jsonEncode(_envelope(['club-a', 'club-b'])), 200);
      });
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      final ids = await repo.getJoinedClubIds();

      expect(seen!.method, 'GET');
      expect(seen!.url.path, '/api/v1/club-memberships');
      expect(ids, {'club-a', 'club-b'});
    });

    test('joinClub posts to /clubs/{id}/join', () async {
      http.Request? seen;
      final mock = MockClient((request) async {
        seen = request;
        return http.Response(jsonEncode(_envelope({'joined': true})), 200);
      });
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      await repo.joinClub('club-1');

      expect(seen!.method, 'POST');
      expect(seen!.url.path, '/api/v1/clubs/club-1/join');
    });

    test('leaveClub posts to /clubs/{id}/leave', () async {
      http.Request? seen;
      final mock = MockClient((request) async {
        seen = request;
        return http.Response(jsonEncode(_envelope({'joined': false})), 200);
      });
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      await repo.leaveClub('club-1');

      expect(seen!.method, 'POST');
      expect(seen!.url.path, '/api/v1/clubs/club-1/leave');
    });

    test('joining a club that does not exist surfaces CLUB_NOT_FOUND', () async {
      final mock = MockClient((request) async => http.Response(
            jsonEncode({
              'data': null,
              'meta': {'request_id': 'req-test'},
              'error': {'code': 'CLUB_NOT_FOUND', 'message': 'Club not found.'},
            }),
            404,
          ));
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      try {
        await repo.joinClub('yok');
        fail('expected ApiClientException');
      } on ApiClientException catch (e) {
        expect(e.statusCode, 404);
        expect(e.code, 'CLUB_NOT_FOUND');
      }
    });
  });
}
