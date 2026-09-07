import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/features/map/tour_360_screen.dart';

/// Opens a 360° tour **inside the app** (full-screen WebView).
///
/// External browser launch was removed: ARUCAD tours load as the WebView's
/// top-level document (X-Frame-Options only blocked iframe embeds).
Future<void> open360Tour(
  BuildContext context,
  String url, {
  String? tourTarget,
  String? title,
}) async {
  final resolved = composeTourUrl(url, tourTarget);
  if (!context.mounted) return;
  await Navigator.of(context).push(
    MaterialPageRoute<void>(
      builder: (_) => Tour360Screen(
        url: resolved,
        title: title,
      ),
      fullscreenDialog: true,
    ),
  );
}

/// Attaches a scene / hotspot target when the 360 directory provides one.
String composeTourUrl(String url, String? tourTarget) {
  final target = tourTarget?.trim();
  if (target == null || target.isEmpty) return url;
  final uri = Uri.tryParse(url);
  if (uri == null) return url;
  if (uri.fragment.isNotEmpty) return url;
  if (target.contains('=') || target.startsWith('?')) {
    final cleaned = target.startsWith('?') ? target.substring(1) : target;
    return uri.replace(queryParameters: {
      ...uri.queryParameters,
      ...Uri.splitQueryString(cleaned),
    }).toString();
  }
  return uri.replace(fragment: target).toString();
}
