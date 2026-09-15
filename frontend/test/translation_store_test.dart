import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/l10n/translation_store.dart';

/// Words published from the Admin panel have to reach the phone, and the
/// phone has to keep working when they cannot.
///
/// The design is bundle **plus** remote override: the strings compiled into
/// the app are a complete reviewed copy, and anything published layers on
/// top. So the tests that matter most are the ones where the network is
/// absent or broken — a student on a plane sees last release's wording,
/// never an empty screen and never a raw key.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    AppStrings.clearOverrides();
  });

  tearDown(AppStrings.clearOverrides);

  http.Client serving(Map<String, String> strings, {String etag = '"v1"'}) {
    return MockClient((request) async {
      if (request.headers['If-None-Match'] == etag) {
        return http.Response('', 304, headers: {'etag': etag});
      }
      return http.Response(
        jsonEncode({
          'data': {
            'locale': request.url.queryParameters['lang'],
            'version': etag,
            'fallback': 'tr',
            'strings': strings,
          },
          'meta': {},
          'error': null,
        }),
        200,
        headers: {'etag': etag, 'content-type': 'application/json'},
      );
    });
  }

  // ---- the bundle is the floor -------------------------------------

  test('without any download the bundled string is used', () {
    const strings = AppStrings(AppLanguage.en);

    expect(strings.t('nav_profile'), 'Profile');
  });

  test('a published override wins over the bundle', () async {
    final store = TranslationStore(
      baseUrl: 'https://api.test',
      client: serving({'nav_profile': 'My Account'}),
    );

    expect(await store.refresh(AppLanguage.en), isTrue);
    expect(const AppStrings(AppLanguage.en).t('nav_profile'), 'My Account');
  });

  test('an unknown key still falls back to the key, never to empty', () {
    expect(const AppStrings(AppLanguage.en).t('no_such_key'), 'no_such_key');
  });

  // ---- offline and failure ------------------------------------------

  test('a network failure leaves the bundled strings in place', () async {
    final store = TranslationStore(
      baseUrl: 'https://api.test',
      client: MockClient((_) async => throw const SocketExceptionLike()),
    );

    expect(await store.refresh(AppLanguage.en), isFalse);
    expect(const AppStrings(AppLanguage.en).t('nav_profile'), 'Profile');
  });

  test('a server error leaves the bundled strings in place', () async {
    final store = TranslationStore(
      baseUrl: 'https://api.test',
      client: MockClient((_) async => http.Response('nope', 500)),
    );

    expect(await store.refresh(AppLanguage.en), isFalse);
    expect(const AppStrings(AppLanguage.en).t('nav_profile'), 'Profile');
  });

  test('an empty catalogue is ignored rather than blanking the app', () async {
    final store = TranslationStore(
      baseUrl: 'https://api.test',
      client: serving(const {}),
    );

    expect(await store.refresh(AppLanguage.en), isFalse);
    expect(const AppStrings(AppLanguage.en).t('nav_profile'), 'Profile');
  });

  // ---- caching -------------------------------------------------------

  test('the download survives a restart', () async {
    final store = TranslationStore(
      baseUrl: 'https://api.test',
      client: serving({'nav_profile': 'My Account'}),
    );
    await store.refresh(AppLanguage.en);

    // A new launch: overrides are gone from memory, cache is not.
    AppStrings.clearOverrides();
    expect(const AppStrings(AppLanguage.en).t('nav_profile'), 'Profile');

    await TranslationStore.loadCached();
    expect(const AppStrings(AppLanguage.en).t('nav_profile'), 'My Account');
  });

  test('an unchanged catalogue is not downloaded twice', () async {
    var bodies = 0;
    final client = MockClient((request) async {
      if (request.headers['If-None-Match'] == '"v1"') {
        return http.Response('', 304, headers: {'etag': '"v1"'});
      }
      bodies++;
      return http.Response(
        jsonEncode({
          'data': {'strings': {'nav_profile': 'My Account'}},
          'meta': {},
          'error': null,
        }),
        200,
        headers: {'etag': '"v1"'},
      );
    });

    final store = TranslationStore(baseUrl: 'https://api.test', client: client);

    expect(await store.refresh(AppLanguage.en), isTrue);
    expect(await store.refresh(AppLanguage.en), isFalse,
        reason: 'The second call should have been a 304.');
    expect(bodies, 1, reason: 'The catalogue was downloaded twice.');
  });

  // ---- placeholders --------------------------------------------------

  test('placeholders are filled in', () async {
    final store = TranslationStore(
      baseUrl: 'https://api.test',
      client: serving({'welcome': 'Welcome, {name}!'}),
    );
    await store.refresh(AppLanguage.en);

    expect(
      const AppStrings(AppLanguage.en).t('welcome', {'name': 'Ezgi'}),
      'Welcome, Ezgi!',
    );
  });

  test('a missing argument leaves no stray braces text', () async {
    final store = TranslationStore(
      baseUrl: 'https://api.test',
      client: serving({'welcome': 'Welcome, {name}!'}),
    );
    await store.refresh(AppLanguage.en);

    // Nothing supplied: the placeholder stays visible rather than the app
    // rendering a half-sentence. Visible is debuggable; silent is not.
    expect(const AppStrings(AppLanguage.en).t('welcome'), 'Welcome, {name}!');
  });

  // ---- plurals -------------------------------------------------------

  test('English plural picks one and other', () async {
    final store = TranslationStore(
      baseUrl: 'https://api.test',
      client: serving({
        'msgs': jsonEncode({'one': '{count} message', 'other': '{count} messages'}),
      }),
    );
    await store.refresh(AppLanguage.en);

    const strings = AppStrings(AppLanguage.en);
    expect(strings.plural('msgs', 1), '1 message');
    expect(strings.plural('msgs', 5), '5 messages');
  });

  /// Russian is the reason plurals are modelled at all: treating it like
  /// English produces "2 сообщений", which reads as broken to a native
  /// speaker.
  test('Russian plural picks one, few and many', () async {
    final store = TranslationStore(
      baseUrl: 'https://api.test',
      client: serving({
        'msgs': jsonEncode({
          'one': '{count} сообщение',
          'few': '{count} сообщения',
          'many': '{count} сообщений',
        }),
      }),
    );
    await store.refresh(AppLanguage.ru);

    const strings = AppStrings(AppLanguage.ru);
    expect(strings.plural('msgs', 1), '1 сообщение');
    expect(strings.plural('msgs', 2), '2 сообщения');
    expect(strings.plural('msgs', 5), '5 сообщений');
    expect(strings.plural('msgs', 11), '11 сообщений', reason: '11 is "many".');
    expect(strings.plural('msgs', 21), '21 сообщение', reason: '21 is "one".');
  });

  test('a plain string used as a plural still renders', () async {
    final store = TranslationStore(
      baseUrl: 'https://api.test',
      client: serving({'msgs': '{count} items'}),
    );
    await store.refresh(AppLanguage.en);

    expect(const AppStrings(AppLanguage.en).plural('msgs', 3), '3 items');
  });
}

/// A stand-in for a socket failure — the point is that `refresh` swallows
/// whatever the network throws.
class SocketExceptionLike implements Exception {
  const SocketExceptionLike();
}
