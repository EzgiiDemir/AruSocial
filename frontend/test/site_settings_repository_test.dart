import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/core/services/rest_campus_repository.dart';
import 'package:arucad_campus_prototype/core/services/site_settings_store.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  test('SiteSettings.fromJson reads public fields and ignores leaked secrets', () {
    final settings = SiteSettings.fromJson({
      'entra': {
        'tenantId': 'tenant-1',
        'clientId': 'client-1',
        'redirectUri': 'app://redirect',
      },
      'wordpress': {
        'siteUrl': 'https://cms.example.com',
        'apiTokenConfigured': true,
        'apiToken': 'should-not-land-in-the-model',
        'clientSecret': 'also-not',
      },
      'apiKey': 'nope',
      'clientSecret': 'nope',
    });

    expect(settings.entra.tenantId, 'tenant-1');
    expect(settings.entra.clientId, 'client-1');
    expect(settings.entra.redirectUri, 'app://redirect');
    expect(settings.wordpressSiteUrl, 'https://cms.example.com');
    expect(settings.wordpressApiTokenConfigured, isTrue);
    expect(settings.wordpressApiToken, isEmpty);
  });

  test('MockCampusRepository site settings stay on SiteSettingsStore', () async {
    final repo = MockCampusRepository();

    await repo.updateSiteSettings(
      entra: const EntraSiteConfig(
        tenantId: 't-mock',
        clientId: 'c-mock',
        redirectUri: 'app://mock',
      ),
      wordpressSiteUrl: 'https://wp.example.com',
      wordpressApiToken: 'local-token',
    );

    final got = await repo.getSiteSettings();
    expect(got.entra.tenantId, 't-mock');
    expect(got.wordpressSiteUrl, 'https://wp.example.com');
    expect(got.wordpressApiTokenConfigured, isTrue);
    expect(got.wordpressApiToken, 'local-token');

    final stored = await SiteSettingsStore.wordpress();
    expect(stored.apiToken, 'local-token');
  });

  test('RestCampusRepository hits /admin/settings/site and does not write SharedPreferences',
      () async {
    final calls = <http.Request>[];
    final mock = MockClient((request) async {
      calls.add(request);
      if (request.method == 'GET' && request.url.path.endsWith('/admin/settings/site')) {
        return http.Response(
          jsonEncode({
            'data': {
              'entra': {
                'tenantId': 't-rest',
                'clientId': 'c-rest',
                'redirectUri': 'app://rest',
              },
              'wordpress': {
                'siteUrl': 'https://cms.example.com',
                'apiTokenConfigured': true,
                'apiToken': 'leaked-from-server',
              },
            },
            'meta': {},
            'error': null,
          }),
          200,
        );
      }
      if (request.method == 'POST' && request.url.path.endsWith('/admin/settings/site')) {
        return http.Response(
          jsonEncode({
            'data': {
              'entra': {
                'tenantId': 't-new',
                'clientId': 'c-new',
                'redirectUri': 'app://new',
              },
              'wordpress': {
                'siteUrl': 'https://new.example.com',
                'apiTokenConfigured': true,
              },
            },
            'meta': {},
            'error': null,
          }),
          200,
        );
      }
      return http.Response(jsonEncode({'data': {}, 'meta': {}, 'error': null}), 404);
    });

    final repo = RestCampusRepository(
      client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
    );

    final got = await repo.getSiteSettings();
    expect(calls.single.method, 'GET');
    expect(calls.single.url.path, '/api/v1/admin/settings/site');
    expect(got.entra.tenantId, 't-rest');
    expect(got.wordpressSiteUrl, 'https://cms.example.com');
    expect(got.wordpressApiTokenConfigured, isTrue);
    expect(got.wordpressApiToken, isEmpty);

    final updated = await repo.updateSiteSettings(
      entra: const EntraSiteConfig(
        tenantId: 't-new',
        clientId: 'c-new',
        redirectUri: 'app://new',
      ),
      wordpressSiteUrl: 'https://new.example.com',
      wordpressApiToken: 'wp-secret-token',
    );
    expect(calls.last.method, 'POST');
    expect(calls.last.url.path, '/api/v1/admin/settings/site');
    final posted = jsonDecode(calls.last.body) as Map<String, dynamic>;
    expect(posted['entra']['tenantId'], 't-new');
    expect(posted['wordpress']['apiToken'], 'wp-secret-token');
    expect(updated.wordpressApiToken, isEmpty);

    final prefs = await SharedPreferences.getInstance();
    expect(prefs.getString('site.entra.tenantId'), isNull);
    expect(prefs.getString('site.wordpress.apiToken'), isNull);
  });

  test('REST site-settings UI and RestCampusRepository do not use SiteSettingsStore', () {
    const paths = [
      'lib/features/admin/admin_panel_screen.dart',
      'lib/core/services/rest_campus_repository.dart',
    ];
    for (final rel in paths) {
      final src = File(rel).readAsStringSync();
      expect(src.contains('SiteSettingsStore.'), isFalse, reason: rel);
    }
  });

  test('admin site-settings tab shows a toast only after the repository returns', () {
    final src = File('lib/features/admin/admin_panel_screen.dart').readAsStringSync();
    expect(src.contains('await widget.repository.updateSiteSettings('), isTrue);
    expect(src.contains('admin_entra_saved_toast'), isTrue);
    expect(src.contains('admin_wp_saved_toast'), isTrue);
    expect(src.contains('SiteSettingsStore.setEntra'), isFalse);
    expect(src.contains('SiteSettingsStore.setWordPress'), isFalse);
  });
}
