import 'dart:convert';
import 'dart:typed_data';

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

  // Real multipart upload (FAZ 6A §7) — `post()` above can only ever send
  // JSON, so a real file upload to a real backend endpoint (not a base64
  // string smuggled into a JSON body) needed genuinely new capability, not
  // just another repository method.
  Future<Map<String, dynamic>> postMultipart(
    String path, {
    required Uint8List bytes,
    required String fileName,
    required String fieldName,
    Map<String, String>? fields,
  }) async {
    final request = http.MultipartRequest('POST', _uri(path));
    final authHeaders = await authTokenAdapter?.authorizeHeaders() ?? {};
    request.headers.addAll(authHeaders);
    request.fields.addAll(fields ?? {});
    request.files.add(http.MultipartFile.fromBytes(fieldName, bytes, filename: fileName));
    final streamed = await _client.send(request);
    final response = await http.Response.fromStream(streamed);
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
