// ignore_for_file: avoid_web_libraries_in_flutter, deprecated_member_use
import 'dart:html' as html;
import 'dart:ui_web' as ui_web;

import 'package:flutter/material.dart';

// Real fix for "360 tours only open in an external tab on Flutter web": an
// <iframe> pointed directly at 360.arucad.edu.tr is blocked by that host's
// X-Frame-Options. This instead points the iframe at our own backend's
// /tour-proxy (see TourProxyController), which serves the same tour from
// OUR origin with that header stripped — an in-app embed, not a new tab.
final Set<String> _registeredViewTypes = {};

Widget buildTourIframe(String url) {
  final viewType = 'arucad-360-tour-${url.hashCode}';
  if (_registeredViewTypes.add(viewType)) {
    ui_web.platformViewRegistry.registerViewFactory(viewType, (int viewId) {
      final iframe = html.IFrameElement()
        ..src = url
        ..style.border = 'none'
        ..style.width = '100%'
        ..style.height = '100%'
        ..allow = 'accelerometer; gyroscope; fullscreen; xr-spatial-tracking'
        ..allowFullscreen = true;
      return iframe;
    });
  }
  return HtmlElementView(viewType: viewType);
}
