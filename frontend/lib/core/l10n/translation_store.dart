import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';

/// Keeps the app's words up to date without an App Store release.
///
/// Bundle **plus** remote override, deliberately, rather than remote only:
/// the strings compiled into `app_strings.dart` are what a student sees on
/// first launch, on a plane, and when the server is unreachable. Anything
/// published from the Admin panel layers on top.
///
/// The order it runs in matters. [loadCached] is synchronous-ish and
/// applies last night's download before the first frame, so the app never
/// flashes bundled text and then swaps. [refresh] then asks the server,
/// which almost always answers 304 because of the stored version.
class TranslationStore {
  TranslationStore({required this.baseUrl, http.Client? client})
      : _client = client ?? http.Client();

  final String baseUrl;
  final http.Client _client;

  static const _payloadKey = 'l10n.payload.v1';
  static const _versionKey = 'l10n.version.v1';

  /// Applies whatever was downloaded last time.
  ///
  /// Failure here is not an error worth surfacing: the bundled strings are
  /// a complete, reviewed copy of the app, so the worst case is that a
  /// student sees last release's wording for a moment longer.
  static Future<void> loadCached() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final raw = prefs.getString(_payloadKey);
      if (raw == null) return;

      AppStrings.applyOverrides(_decode(raw));
    } catch (_) {
      // Corrupt or unreadable cache — fall back to the bundle.
    }
  }

  /// Asks the server for anything newer.
  ///
  /// Returns true when new strings were applied. Sends the stored version
  /// as `If-None-Match`, so the usual answer is 304 with no body — which is
  /// what makes checking on every launch cheap enough to actually do.
  Future<bool> refresh(AppLanguage language) async {
    try {
      final prefs = await SharedPreferences.getInstance();
      final known = prefs.getString(_versionKey);

      // baseUrl already ends in /api/v1 (config.apiBaseUrl), so the path must
      // NOT repeat it — otherwise the request goes to /api/v1/api/v1/... (404).
      final uri = Uri.parse('$baseUrl/translations')
          .replace(queryParameters: {'lang': language.name});

      final response = await _client.get(uri, headers: {
        'Accept': 'application/json',
        if (known != null) 'If-None-Match': known,
      }).timeout(const Duration(seconds: 8));

      if (response.statusCode == 304) return false;
      if (response.statusCode != 200) return false;

      final body = jsonDecode(utf8.decode(response.bodyBytes));
      final data = body is Map ? body['data'] : null;
      if (data is! Map) return false;

      final strings = data['strings'];
      if (strings is! Map || strings.isEmpty) return false;

      // Stored per language rather than merged across all three: the phone
      // only ever renders one, and holding three triples the cache for no
      // benefit.
      final payload = jsonEncode({language.name: strings});

      await prefs.setString(_payloadKey, payload);
      final etag = response.headers['etag'];
      if (etag != null) {
        await prefs.setString(_versionKey, etag);
      }

      AppStrings.applyOverrides(_decode(payload));

      return true;
    } catch (_) {
      // Offline, timing out, or a server that is having a bad day. The
      // bundled strings are already on screen and still correct.
      return false;
    }
  }

  /// Forgets the downloaded copy and goes back to the bundle.
  @visibleForTesting
  static Future<void> clear() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_payloadKey);
    await prefs.remove(_versionKey);
    AppStrings.clearOverrides();
  }

  static Map<AppLanguage, Map<String, String>> _decode(String raw) {
    final decoded = jsonDecode(raw);
    if (decoded is! Map) return const {};

    final out = <AppLanguage, Map<String, String>>{};

    for (final entry in decoded.entries) {
      final language = AppLanguage.values
          .where((l) => l.name == '${entry.key}')
          .firstOrNull;
      final values = entry.value;
      if (language == null || values is! Map) continue;

      out[language] = values.map((k, v) => MapEntry('$k', '$v'));
    }

    return out;
  }
}
