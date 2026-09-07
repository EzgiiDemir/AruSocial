/// Rewrites media/avatar URLs so a phone talking to a LAN API host can
/// still load files the backend stamped with APP_URL (often 127.0.0.1).
class MediaUrl {
  static String? _origin;
  static String? _apiBase;

  /// The full `http://host:port/api/v1` this session is bound to — used by
  /// [Tour360View] (web) to build its own `/tour-proxy` request.
  static String? get apiBase => _apiBase;

  /// [apiBase] is `http://host:port/api/v1`.
  static void bindApiBase(String apiBase) {
    _apiBase = apiBase;
    final uri = Uri.tryParse(apiBase);
    if (uri == null || uri.host.isEmpty) {
      _origin = null;
      return;
    }
    final port = uri.hasPort ? ':${uri.port}' : '';
    _origin = '${uri.scheme}://${uri.host}$port';
  }

  static String? resolve(String? url, {String? cacheBust}) {
    if (url == null || url.isEmpty) return url;
    if (url.startsWith('data:')) return url;
    var resolved = url;
    final parsed = Uri.tryParse(url);
    final origin = _origin;
    if (parsed != null && origin != null) {
      final loopback = parsed.host == 'localhost' ||
          parsed.host == '127.0.0.1' ||
          parsed.host == '0.0.0.0' ||
          parsed.host.isEmpty;
      if (loopback) {
        final path = parsed.path.isEmpty ? '/' : parsed.path;
        final query = parsed.hasQuery ? '?${parsed.query}' : '';
        resolved = '$origin$path$query';
      }
    }
    // Flutter web Image.network uses XHR; /storage/* is served by the PHP
    // built-in server without CORS. Rewrite onto the API file route.
    resolved = _storageToApi(resolved, origin);
    if (cacheBust != null && cacheBust.isNotEmpty) {
      final joiner = resolved.contains('?') ? '&' : '?';
      resolved = '$resolved${joiner}v=$cacheBust';
    }
    return resolved;
  }

  static String _storageToApi(String url, String? origin) {
    final match =
        RegExp(r'^(https?://[^/]+)?/storage/(media(?:/video)?/[^/?#]+)').firstMatch(url);
    if (match == null) return url;
    final base = match.group(2)!.split('/').last;
    final host = (origin != null && origin.isNotEmpty) ? origin : match.group(1);
    if (host != null && host.isNotEmpty) {
      return '$host/api/v1/media/file/$base';
    }
    return '/api/v1/media/file/$base';
  }
}
