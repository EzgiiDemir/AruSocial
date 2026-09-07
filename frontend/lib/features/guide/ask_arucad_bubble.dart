import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/map/map_pointer_guard.dart';

/// The circular "Ask ARUCAD" floating entry point shown inside every real
/// map surface (Home/Kampüs Nabzı, in-app navigation, ...) — top-right by
/// convention, so it never collides with the bottom route/mode controls
/// those screens already use. Shared so every map keeps the exact same
/// look rather than each screen re-implementing its own circle button.
class AskArucadBubble extends StatelessWidget {
  final VoidCallback onTap;
  const AskArucadBubble({super.key, required this.onTap});

  @override
  Widget build(BuildContext context) => MapPointerGuard(
        child: Material(
          color: Colors.white,
          shape: const CircleBorder(),
          clipBehavior: Clip.antiAlias,
          child: InkWell(
            onTap: onTap,
            customBorder: const CircleBorder(),
            hoverColor: ArucadColors.primary.withValues(alpha: .06),
            splashColor: ArucadColors.primary.withValues(alpha: .12),
            highlightColor: ArucadColors.primary.withValues(alpha: .08),
            child: SizedBox(
              width: 52,
              height: 52,
              child: ClipOval(
                // The supplied mark has white canvas around it. Zoom it just
                // enough inside the circle so its coloured form is visually
                // centred rather than leaving an empty lower edge.
                child: Transform.scale(
                  scale: 1.32,
                  child: Image(
                    image: AssetImage('assets/images/galatea.png'),
                    fit: BoxFit.cover,
                  ),
                ),
              ),
            ),
          ),
        ),
      );
}
