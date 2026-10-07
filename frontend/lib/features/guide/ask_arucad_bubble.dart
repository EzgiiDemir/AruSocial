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
              width: 60,
              height: 60,
              child: Center(
                child: Transform.translate(
                  // The artwork's alpha-weighted visual centre is left and
                  // below its square canvas centre. This small optical offset
                  // centres the coloured mark, not merely the PNG bounds.
                  offset: const Offset(5, -4),
                  child: Transform.scale(
                    scale: 1.12,
                    alignment: Alignment.center,
                    child: Image.asset(
                      'assets/images/aruverse_mark.png',
                      width: 52,
                      height: 52,
                      fit: BoxFit.contain,
                      alignment: Alignment.center,
                      filterQuality: FilterQuality.high,
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      );
}
