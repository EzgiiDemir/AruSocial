import 'package:flutter/foundation.dart';
import 'package:url_launcher/url_launcher.dart';

import 'contracts.dart';

/// Map provider scaffold that can open Mapbox/Google Maps links.
class MapboxMapProvider implements MapProvider {
  final String? mapboxAccessToken;

  const MapboxMapProvider({this.mapboxAccessToken});

  @override
  Future<void> openTour(String tourUrl) async {
    final uri = Uri.parse(tourUrl);
    final ok = await launchUrl(
      uri,
      mode: kIsWeb ? LaunchMode.platformDefault : LaunchMode.externalApplication,
      webOnlyWindowName: '_blank',
    );
    if (!ok) {
      throw Exception('Unable to open tour URL');
    }
  }

  @override
  Future<void> startRoute(
      {required String destination, bool accessibleOnly = false}) async {
    // For now open Google Maps directions; production should integrate native SDKs.
    final encoded = Uri.encodeComponent(destination);
    final url = Uri.parse(
        'https://www.google.com/maps/dir/?api=1&destination=$encoded');
    if (!await launchUrl(url)) throw Exception('Unable to launch maps');
  }
}
