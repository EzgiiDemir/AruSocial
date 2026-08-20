import 'package:url_launcher/url_launcher.dart';

import 'contracts.dart';

class UrlLauncherMapProvider implements MapProvider {
  const UrlLauncherMapProvider();

  @override
  Future<void> openTour(String tourUrl) async {
    final uri = Uri.tryParse(tourUrl);
    if (uri == null) {
      throw MapProviderException('Geçersiz 360 linki: $tourUrl');
    }
    await _launch(uri);
  }

  @override
  Future<void> startRoute(
      {required String destination, bool accessibleOnly = false}) async {
    final uri = Uri.https('www.google.com', '/maps/dir/', {
      'api': '1',
      'destination': destination,
      'travelmode': 'walking',
      if (accessibleOnly) 'avoid': 'ferries|highways',
    });
    await _launch(uri);
  }

  Future<void> _launch(Uri uri) async {
    if (!await canLaunchUrl(uri)) {
      throw MapProviderException('Harita başlatılamadı: $uri');
    }
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) {
      throw MapProviderException('Harita bağlantısı açılamadı.');
    }
  }
}

class MapProviderException implements Exception {
  MapProviderException(this.message);

  final String message;

  @override
  String toString() => 'MapProviderException: $message';
}
