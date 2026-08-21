import 'dart:convert';

import 'package:http/http.dart' as http;

import 'auth_token_adapter.dart';

class ApiClient {
  ApiClient({required this.baseUrl, http.Client? client, this.authTokenAdapter})
      : _client = client ?? http.Client();

  final String baseUrl;
  final http.Client _client;
  final AuthTokenAdapter? authTokenAdapter;

  Uri _uri(String path) {
    final normalizedPath = path.startsWith('/') ? path : '/$path';
    return Uri.parse('$baseUrl$normalizedPath');
  }

  Future<Map<String, dynamic>> get(
    String path, {
    Map<String, String>? headers,
  }) async {
    final response = await _client.get(
      _uri(path),
      headers: await _combinedHeaders(headers),
    );
    return _decodeResponse(response);
  }

  Future<Map<String, dynamic>> post(
    String path, {
    Map<String, String>? headers,
    Object? body,
  }) async {
    final response = await _client.post(
      _uri(path),
      headers: await _combinedHeaders(headers),
      body: body == null ? null : jsonEncode(body),
    );
    return _decodeResponse(response);
  }

  Future<Map<String, String>> _combinedHeaders(
      Map<String, String>? headers) async {
    final authHeaders = await authTokenAdapter?.authorizeHeaders() ?? {};
    return {
      'Content-Type': 'application/json',
      ...authHeaders,
      ...?headers,
    };
  }

  Map<String, dynamic> _decodeResponse(http.Response response) {
    if (response.statusCode < 200 || response.statusCode >= 300) {
      // The backend's real {data, meta, error} envelope carries a
      // machine-readable error.code (e.g. CONTENT_BLOCKED,
      // ACCOUNT_BANNED) — parsed out here so callers can react to a
      // specific real failure instead of pattern-matching a raw string.
      String? code;
      String message = 'HTTP ${response.statusCode}: ${response.body}';
      try {
        final decoded = jsonDecode(response.body);
        if (decoded is Map<String, dynamic>) {
          final error = decoded['error'];
          if (error is Map<String, dynamic>) {
            code = error['code'] as String?;
            message = error['message'] as String? ?? message;
          }
        }
      } catch (_) {
        // Body wasn't the real envelope (e.g. a raw Laravel error page) —
        // the generic HTTP-status message above stays as the fallback.
      }
      throw ApiClientException(message, code: code, statusCode: response.statusCode);
    }

    final body = jsonDecode(response.body);
    if (body is! Map<String, dynamic>) {
      throw ApiClientException('Unexpected response body format');
    }
    return body;
  }
}

class ApiClientException implements Exception {
  ApiClientException(this.message, {this.code, this.statusCode});

  final String message;
  final String? code;
  final int? statusCode;

  @override
  String toString() => 'ApiClientException: $message';
}
