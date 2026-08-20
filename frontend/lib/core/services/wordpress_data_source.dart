import 'dart:convert';

import 'package:http/http.dart' as http;

/// A real HTTP client for pulling ARUCAD's WordPress/WPForms data into the
/// app — this makes an actual network call, it does not fake a response.
///
/// WPForms' free tier does not ship a public REST API on its own, so this
/// targets the conventional path exposed by the "WPForms REST API" add-on
/// (or an equivalent custom endpoint): `{site}/wp-json/wpforms/v1/forms` and
/// `/entries`, bearer-token authenticated. If ARUCAD's real site exposes a
/// different path, only [_formsPath]/[_entriesPath] need to change — the
/// request/parsing logic stays the same.
class WordPressDataSource {
  static const _formsPath = '/wp-json/wpforms/v1/forms';
  static const _entriesPath = '/wp-json/wpforms/v1/entries';

  static Future<List<Map<String, dynamic>>> fetchForms({
    required String siteUrl,
    required String apiToken,
  }) =>
      _get(siteUrl, _formsPath, apiToken);

  static Future<List<Map<String, dynamic>>> fetchEntries({
    required String siteUrl,
    required String apiToken,
    String? formId,
  }) {
    final path = (formId == null || formId.isEmpty)
        ? _entriesPath
        : '$_entriesPath?form_id=$formId';
    return _get(siteUrl, path, apiToken);
  }

  static Future<List<Map<String, dynamic>>> _get(
      String siteUrl, String path, String apiToken) async {
    final trimmed = siteUrl.trim();
    if (trimmed.isEmpty) {
      throw Exception('Kaynak Site URL boş olamaz.');
    }
    final base = trimmed.replaceAll(RegExp(r'/+$'), '');
    final uri = Uri.parse('$base$path');

    final http.Response res;
    try {
      res = await http.get(uri, headers: {
        if (apiToken.trim().isNotEmpty)
          'Authorization': 'Bearer ${apiToken.trim()}',
        'Accept': 'application/json',
      }).timeout(const Duration(seconds: 12));
    } catch (e) {
      throw Exception('Siteye ulaşılamadı: $e');
    }

    if (res.statusCode != 200) {
      throw Exception('HTTP ${res.statusCode}: ${_shorten(res.body)}');
    }

    final decoded = jsonDecode(utf8.decode(res.bodyBytes));
    if (decoded is List) {
      return decoded.cast<Map<String, dynamic>>();
    }
    if (decoded is Map && decoded['data'] is List) {
      return (decoded['data'] as List).cast<Map<String, dynamic>>();
    }
    throw Exception('Beklenmeyen yanıt biçimi (liste bekleniyordu).');
  }

  static String _shorten(String body) =>
      body.length > 200 ? '${body.substring(0, 200)}…' : body;
}
