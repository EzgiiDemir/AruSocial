import 'package:flutter/material.dart';
import 'package:pointer_interceptor/pointer_interceptor.dart';

/// MapLibre on web is an HtmlElementView that sits above Flutter widgets
/// in the same pixel area and swallows taps. Wrap every HUD control that
/// is stacked on a [CampusMapView] with this so the button actually fires.
class MapPointerGuard extends StatelessWidget {
  final Widget child;
  const MapPointerGuard({super.key, required this.child});

  @override
  Widget build(BuildContext context) => PointerInterceptor(child: child);
}
