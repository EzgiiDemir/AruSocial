class ChatRealtimeConfig {
  ChatRealtimeConfig({
    required this.appKey,
    required this.host,
    required this.port,
    required this.scheme,
    required this.authUri,
  });

  final String appKey;
  final String host;
  final int port;
  final String scheme;
  final Uri authUri;

  factory ChatRealtimeConfig.fromEnvironment({required String apiBaseUrl}) {
    final api = Uri.parse(apiBaseUrl);
    const key = String.fromEnvironment('REVERB_APP_KEY', defaultValue: 'arucad-local-key');
    const hostOverride = String.fromEnvironment('REVERB_HOST');
    const port = int.fromEnvironment('REVERB_PORT', defaultValue: 8080);
    const schemeOverride = String.fromEnvironment('REVERB_SCHEME');
    final host = hostOverride.isNotEmpty ? hostOverride : api.host;
    var scheme = schemeOverride.isNotEmpty ? schemeOverride : (api.scheme == 'https' ? 'wss' : 'ws');
    if (scheme == 'http') scheme = 'ws';
    if (scheme == 'https') scheme = 'wss';
    final origin = '${api.scheme}://${api.host}${api.hasPort ? ':${api.port}' : ''}';
    return ChatRealtimeConfig(
      appKey: key,
      host: host,
      port: port,
      scheme: scheme,
      authUri: Uri.parse('$origin/broadcasting/auth'),
    );
  }

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
