import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/ask_arucad_store.dart';
import 'package:arucad_campus_prototype/core/services/rest_campus_repository.dart';

/// Citations come from the backend's record of what it retrieved, never
/// from links parsed out of the answer — an invented URL would otherwise
/// arrive with an invented citation attached.

RestCampusRepository _repo(MockClient client) => RestCampusRepository(
      client: ApiClient(baseUrl: 'http://example.com/api/v1', client: client),
    );

MockClient _askReturning(Map<String, dynamic> data,
    {List<http.Request>? calls}) {
  return MockClient((request) async {
    calls?.add(request);
    return http.Response(
      jsonEncode({'data': data, 'meta': {}, 'error': null}),
      200,
    );
  });
}

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  group('AskArucadSource', () {
    test('parses a web source and a campus source differently', () {
      final web = AskArucadSource.fromJson(const {
        'type': 'web',
        'title': 'Burslar ve Ücretler',
        'url': 'https://arucad.edu.tr/burslar/',
        'id': 'abc',
      });
      expect(web.isWeb, isTrue);
      expect(web.title, 'Burslar ve Ücretler');

      // An internal table has nothing to open, and must not render as a
      // dead link.
      final campus = AskArucadSource.fromJson(const {
        'type': 'campus',
        'title': 'Etkinlikler',
        'url': '',
        'id': 'tool:events',
      });
      expect(campus.isWeb, isFalse);
    });

    test('survives a round trip through the saved conversation', () {
      final message = AskArucadMessage(
        fromUser: false,
        text: 'Burs bilgileri sayfada.',
        at: DateTime.utc(2026, 9, 22),
        sources: const [
          AskArucadSource(
            type: 'web',
            title: 'Burslar',
            url: 'https://arucad.edu.tr/burslar/',
            id: 'abc',
          ),
        ],
      );

      final restored = AskArucadMessage.fromJson(message.toJson());
      expect(restored.sources.single.url, 'https://arucad.edu.tr/burslar/');
    });

    test('a message with no sources serialises without the key', () {
      final message = AskArucadMessage(
        fromUser: true,
        text: 'burs?',
        at: DateTime.utc(2026, 9, 22),
      );
      expect(message.toJson().containsKey('sources'), isFalse);
      expect(AskArucadMessage.fromJson(message.toJson()).sources, isEmpty);
    });
  });

  group('askGuide', () {
    test('exposes the sources the backend reported', () async {
      final repo = _repo(_askReturning({
        'answer': 'Burs bilgileri sayfada.',
        'conversationId': 'ask-1',
        'aiMode': 'local',
        'sources': [
          {
            'type': 'web',
            'title': 'Burslar ve Ücretler',
            'url': 'https://arucad.edu.tr/burslar/',
            'id': 'abc',
          },
          {
            'type': 'campus',
            'title': 'Etkinlikler',
            'url': '',
            'id': 'tool:events',
          },
        ],
      }));

      final answer = await repo.askGuide('burs imkanları');

      expect(answer, 'Burs bilgileri sayfada.');
      expect(repo.lastAskSources, hasLength(2));
      expect(repo.lastAskSources.first.url, 'https://arucad.edu.tr/burslar/');
      expect(repo.lastAskSources.last.isWeb, isFalse);
    });

    test('an answer with no sources reports none rather than inventing one',
        () async {
      final repo = _repo(_askReturning({
        'answer': 'Kütüphane Merkez Kampüs, A Blok.',
        'conversationId': 'ask-1',
        'aiMode': 'direct',
        'sources': <dynamic>[],
      }));

      await repo.askGuide('kütüphane nerede');

      expect(repo.lastAskSources, isEmpty);
    });

    test('an older backend without the field does not break the client',
        () async {
      final repo = _repo(_askReturning({
        'answer': 'cevap',
        'conversationId': 'ask-1',
        'aiMode': 'local',
      }));

      await repo.askGuide('soru');

      expect(repo.lastAskSources, isEmpty);
    });

    test('only the most recent turns are sent with the question', () async {
      final calls = <http.Request>[];
      final repo = _repo(_askReturning({
        'answer': 'ok',
        'conversationId': 'ask-1',
        'aiMode': 'local',
        'sources': <dynamic>[],
      }, calls: calls));

      await repo.askGuide(
        'son soru',
        history: [
          for (var i = 0; i < 20; i++)
            (fromUser: i.isEven, text: 'eski tur ${String.fromCharCode(65 + i)}')
        ],
      );

      final body = jsonDecode(calls.single.body) as Map<String, dynamic>;
      final messages = body['messages'] as List<dynamic>;

      // Twelve turns of history plus the new question — raised from six so a
      // fact mentioned early in a consultation is still in view several
      // questions later. The server caps this too and is the authority;
      // this just avoids shipping the lot.
      expect(messages.length, 13);
      expect((messages.last as Map)['content'], 'son soru');
      final texts = messages.map((m) => (m as Map)['content']).join(' ');
      expect(texts, isNot(contains('eski tur A')));
    });
  });
}
