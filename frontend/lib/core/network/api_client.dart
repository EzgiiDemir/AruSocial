import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import 'auth_token_adapter.dart';

class ApiClient {
  ApiClient({required this.baseUrl, http.Client? client, this.authTokenAdapter})
      : _client = client ?? http.Client();

  /// Fired for a mid-session `AUTH_REQUIRED` or `ACCOUNT_BANNED` so the
  /// app can clear the local session instead of leaving the student on a
  /// screen that will 401 forever. Login itself uses different codes
  /// (`INVALID_CREDENTIALS`) and must not trip this.
  static void Function(String code)? onSessionInvalid;

  static const _timeout = Duration(seconds: 20);

  /// `php artisan serve` on Windows is a single process (it cannot fork
  /// workers). More than a couple of overlapping GETs after login used to
  /// sit in PHP's accept queue until the browser timed them out.
  static const _maxConcurrent = 2;

  final String baseUrl;
  final http.Client _client;
  final AuthTokenAdapter? authTokenAdapter;

  int _inflight = 0;
  final List<Completer<void>> _waiters = [];

  Uri _uri(String path) {
    final normalizedPath = path.startsWith('/') ? path : '/$path';
    return Uri.parse('$baseUrl$normalizedPath');
  }

  Future<Map<String, dynamic>> get(
    String path, {
    Map<String, String>? headers,
    Map<String, String>? query,
  }) {
    return _guard(() async {
      var uri = _uri(path);
      if (query != null && query.isNotEmpty) {
        uri = uri.replace(queryParameters: {
          ...uri.queryParameters,
          ...query,
        });
      }
      return _decodeResponse(await _client
          .get(
            uri,
            headers: await _combinedHeaders(headers),
          )
          .timeout(_timeout));
    });
  }

  Future<Map<String, dynamic>> post(
    String path, {
    Map<String, String>? headers,
    Object? body,
  }) {
    return _guard(() async {
      return _decodeResponse(await _client
          .post(
            _uri(path),
            headers: await _combinedHeaders(headers),
            body: body == null ? null : jsonEncode(body),
          )
          .timeout(_timeout));
    });
  }

  Future<Map<String, String>> _combinedHeaders(Map<String, String>? headers,
      {bool json = true}) async {
    final authHeaders = await authTokenAdapter?.authorizeHeaders() ?? {};
    return {
      if (json) 'Content-Type': 'application/json',
      'Accept': 'application/json',
      ...authHeaders,
      ...?headers,
    };
  }

  /// Multipart upload for `/media`. Must not set JSON Content-Type — the
  /// client generates the multipart boundary. Auth headers still apply.
  Future<Map<String, dynamic>> postMultipart(
    String path, {
    required List<int> bytes,
    required String fileName,
    String fieldName = 'file',
    Map<String, String>? fields,
  }) {
    return _guard(() async {
      final request = http.MultipartRequest('POST', _uri(path));
      request.headers.addAll(await _combinedHeaders(null, json: false));
      if (fields != null) request.fields.addAll(fields);
      request.files.add(http.MultipartFile.fromBytes(
        fieldName,
        bytes,
        filename: fileName,
      ));
      final streamed = await _client.send(request).timeout(_timeout);
      return _decodeResponse(await http.Response.fromStream(streamed));
    });
  }

  Future<T> _limitConcurrency<T>(Future<T> Function() run) async {
    while (_inflight >= _maxConcurrent) {
      final gate = Completer<void>();
      _waiters.add(gate);
      await gate.future;
    }
    _inflight++;
    try {
      return await run();
    } finally {
      _inflight--;
      if (_waiters.isNotEmpty) {
        _waiters.removeAt(0).complete();
      }
    }
  }

  Future<Map<String, dynamic>> _guard(
      Future<Map<String, dynamic>> Function() run) async {
    return _limitConcurrency(() async {
      Object? last;
      for (var attempt = 0; attempt < 2; attempt++) {
        try {
          return await run();
        } on ApiClientException {
          rethrow;
        } catch (e) {
          last = e;
          final retryable = isNetworkFailure(e) && attempt == 0;
          if (!retryable) {
            if (isNetworkFailure(e)) {
              throw ApiClientException.network(e, baseUrl: baseUrl);
            }
            rethrow;
          }
          await Future<void>.delayed(const Duration(milliseconds: 400));
        }
      }
      throw ApiClientException.network(last!);
    });
  }

  Future<List<int>> getBytes(String path) {
    return _limitConcurrency(() async {
      final response = await _client
          .get(_uri(path), headers: await _combinedHeaders(null, json: false))
          .timeout(_timeout);
      if (response.statusCode < 200 || response.statusCode >= 300) {
        _decodeResponse(response);
      }
      return response.bodyBytes;
    });
  }

  Map<String, dynamic> _decodeResponse(http.Response response) {
    if (response.statusCode < 200 || response.statusCode >= 300) {
      // The backend's real {data, meta, error} envelope carries a
      // machine-readable error.code (e.g. CONTENT_BLOCKED,
      // ACCOUNT_BANNED) — parsed out here so callers can react to a
      // specific real failure instead of pattern-matching a raw string.
      String? code;
      String message = 'HTTP ${response.statusCode}: ${response.body}';
      Map<String, dynamic>? details;
      try {
        final decoded = jsonDecode(response.body);
        if (decoded is Map<String, dynamic>) {
          final error = decoded['error'];
          if (error is Map<String, dynamic>) {
            code = error['code'] as String?;
            message = error['message'] as String? ?? message;
            final rawDetails = error['details'];
            if (rawDetails is Map<String, dynamic>) {
              details = rawDetails;
            }
          }
        }
      } catch (_) {
        // Body wasn't the real envelope (e.g. a raw Laravel error page) —
        // the generic HTTP-status message above stays as the fallback.
      }
      if (code == 'ACCOUNT_BANNED' ||
          (response.statusCode == 401 &&
              (code == 'AUTH_REQUIRED' || code == null))) {
        onSessionInvalid?.call(code ?? 'AUTH_REQUIRED');
      }
      throw ApiClientException(
        message,
        code: code,
        statusCode: response.statusCode,
        details: details,
      );
    }

    final body = jsonDecode(response.body);
    if (body is! Map<String, dynamic>) {
      throw ApiClientException('Unexpected response body format');
    }
    return body;
  }
}

class ApiClientException implements Exception {
  ApiClientException(this.message, {this.code, this.statusCode, this.details});

  factory ApiClientException.network(Object error, {String? baseUrl}) =>
      ApiClientException(
        describeNetworkFailure(error, baseUrl: baseUrl),
        code: 'NETWORK_UNREACHABLE',
      );

  final String message;
  final String? code;
  final int? statusCode;
  final Map<String, dynamic>? details;

  String get displayMessage {
    switch (code) {
      case 'ACCOUNT_BANNED':
        return 'Bu hesap askıya alındı. Lütfen öğrenci işleri ile iletişime geç.';
      case 'INVALID_CREDENTIALS':
      case 'DOMAIN_NOT_ALLOWED':
      case 'NETWORK_UNREACHABLE':
        return message;
      case 'AUTH_REQUIRED':
        return 'Oturumunuz sona erdi. Lütfen tekrar giriş yapın.';
    }
    if (statusCode == 401) {
      return 'Oturumunuz sona erdi. Lütfen tekrar giriş yapın.';
    }
    if (statusCode == 403) {
      return 'Bu işlem için yetkin yok.';
    }
    if (statusCode == 422) {
      return message;
    }
    if (statusCode == 429) {
      return 'Çok fazla istek gönderildi. Lütfen biraz sonra tekrar dene.';
    }
    if (statusCode != null && statusCode! >= 500) {
      return 'Sunucu hatası. Lütfen daha sonra tekrar dene.';
    }
    return message;
  }

  @override
  String toString() => 'ApiClientException: $message';
}

bool isNetworkFailure(Object error) {
  final s = error.toString();
  return s.contains('SocketException') ||
      s.contains('ClientException') ||
      s.contains('Failed host lookup') ||
      s.contains('Network is unreachable') ||
      s.contains('Connection refused') ||
      s.contains('No route to host') ||
      s.contains('timed out') ||
      s.contains('TimeoutException') ||
      s.contains('Connection timed out') ||
      s.contains('ERR_NETWORK') ||
      s.contains('Failed to fetch') ||
      s.contains('NetworkError') ||
      s.contains('XMLHttpRequest');
}

String describeNetworkFailure(Object error, {String? baseUrl}) {
  final s = error.toString();
  final endpoint = Uri.tryParse(baseUrl ?? '');
  final host = endpoint?.host ?? '';
  final target = host.isEmpty ? '' : ' API adresi: $host.';
  if (s.contains('No route to host') ||
      s.contains('errno = 113') ||
      s.contains('Network is unreachable') ||
      s.contains('errno = 101')) {
    return 'Telefon API sunucusuna ağ üzerinden ulaşamıyor.$target '
        'Telefon ve bilgisayarın aynı ağa bağlı olduğundan emin olun.';
  }
  if (s.contains('Connection refused') || s.contains('errno = 111')) {
    return 'API sunucusu bu adreste bağlantıyı kabul etmiyor.$target '
        'Sunucu ve port yapılandırmasını kontrol edin.';
  }
  if (s.contains('timed out') ||
      s.contains('TimeoutException') ||
      s.contains('errno = 110') ||
      s.contains('ERR_NETWORK')) {
    return 'API sunucusu zamanında yanıt vermedi.$target '
        'Bağlantıyı kontrol edip tekrar deneyin.';
  }
  if (s.contains('Failed host lookup')) {
    return 'API adresi çözülemedi.$target '
        'Uygulama yapılandırmasındaki sunucu adresini kontrol edin.';
  }
  return 'Sunucuya ulaşılamadı.$target '
      'Telefon ve sunucunun aynı ağda olduğundan emin olun.';
}
