import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

/// Opens a 360° tour URL in the real browser/tour app.
///
/// An in-app iframe embed was tried first, but ARUCAD's tour site sends
/// X-Frame-Options headers that block framing entirely — every attempt
/// rendered as a permanently blank/gray box with no error Dart could even
/// detect (browsers don't fire onError for that). Rather than keep a broken
/// "embedded" experience, this opens the real tour directly.
Future<void> open360Tour(BuildContext context, String url) async {
  try {
    final ok =
        await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
    if (!ok && context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('360° tur açılamadı.')));
    }
  } catch (e) {
    if (!context.mounted) return;
    ScaffoldMessenger.of(context)
        .showSnackBar(SnackBar(content: Text('360° tur açılamadı: $e')));
  }
}
