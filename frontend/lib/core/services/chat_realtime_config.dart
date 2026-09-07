class ChatRealtimeConfig {
  ChatRealtimeConfig({
    required this.appKey,
    required this.host,
    required this.port,
    required this.scheme,
    required this.authUri,
    this.isEnabled = false,
  });

  final String appKey;
  final String host;
  final int port;
  final String scheme;
  final Uri authUri;

  /// Realtime is opt-in: a local REST server does not imply that a separate
  /// Reverb process is running. This prevents a browser console retry loop
  /// when developers start only `php artisan serve`.
  final bool isEnabled;

  factory ChatRealtimeConfig.fromEnvironment({required String apiBaseUrl}) {
    final api = Uri.parse(apiBaseUrl);
    const key = String.fromEnvironment('REVERB_APP_KEY',
        defaultValue: 'arucad-local-key');
    const hostOverride = String.fromEnvironment('REVERB_HOST');
    const port = int.fromEnvironment('REVERB_PORT', defaultValue: 8091);
    const schemeOverride = String.fromEnvironment('REVERB_SCHEME');
    const enabledRaw = String.fromEnvironment('REVERB_ENABLED');
    final enabled = switch (enabledRaw.toLowerCase()) {
      'true' || '1' => true,
      _ => false,
    };
    var host = hostOverride.isNotEmpty ? hostOverride : api.host;
    if (_isLoopback(host)) host = '127.0.0.1';
    var scheme = schemeOverride.isNotEmpty
        ? schemeOverride
        : (api.scheme == 'https' ? 'wss' : 'ws');
    if (scheme == 'http') scheme = 'ws';
    if (scheme == 'https') scheme = 'wss';
    final origin =
        '${api.scheme}://${_isLoopback(api.host) ? '127.0.0.1' : api.host}${api.hasPort ? ':${api.port}' : ''}';
    return ChatRealtimeConfig(
      appKey: key,
      host: host,
      port: port,
      scheme: scheme,
      authUri: Uri.parse('$origin/broadcasting/auth'),
      isEnabled: enabled,
    );
  }

  static bool _isLoopback(String host) =>
      host == 'localhost' ||
      host == '127.0.0.1' ||
      host == '::1' ||
      host == '10.0.2.2';

  Uri get websocketUri => Uri(
        scheme: scheme,
        host: host,
        port: port,
        path: '/app/$appKey',
        queryParameters: const {
          'protocol': '7',
          'client': 'flutter',
          'version': '8.4.0',
          'flash': 'false',
        },
      );
}
