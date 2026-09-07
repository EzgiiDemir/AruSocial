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

  group('RestCampusRepository chat thread peers', () {
    test('getChatThreadPeers parses name + avatarUrl + prefs', () async {
      final mock = MockClient((request) async {
        expect(request.url.path, '/api/v1/chat/threads');
        return http.Response(
            jsonEncode(_envelope([
              {
                'name': 'Ege',
                'avatarUrl': 'https://example.com/a.png',
                'muted': true,
                'archived': false,
                'restricted': true,
              },
              {'name': 'Deniz', 'avatarUrl': null},
            ])),
            200);
      });
      final repo = RestCampusRepository(
        client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
      );

      final peers = await repo.getChatThreadPeers(const []);
      expect(peers.length, 2);
      expect(peers[0].name, 'Ege');
      expect(peers[0].avatarUrl, 'https://example.com/a.png');
      expect(peers[0].muted, isTrue);
      expect(peers[0].restricted, isTrue);
      expect(peers[1].avatarUrl, isNull);
      expect(peers[1].muted, isFalse);
    });
  });

  group('MockCampusRepository chat thread peers', () {
    test('getChatThreadPeers wraps names with a null avatarUrl', () async {
      final repo = MockCampusRepository();
      final peers = await repo.getChatThreadPeers(const ['Bilinen Kişi']);
      expect(peers, isA<List>());
      expect(peers.every((p) => p.avatarUrl == null), isTrue);
    });
  });
}
