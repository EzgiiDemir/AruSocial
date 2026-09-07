import 'package:flutter/material.dart';

/// Non-web platforms never call this — [Tour360View] uses `webview_flutter`
/// there instead. Exists only so the conditional import in
/// tour_360_screen.dart always resolves to something.
Widget buildTourIframe(String url) => const SizedBox.shrink();
