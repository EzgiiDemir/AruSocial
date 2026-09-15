import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// The one place Home's list rows agree on their metrics.
///
/// Every stacked list on Home — recommendations, "Kampüste Şimdi",
/// service shortcuts — reads these instead of carrying its own numbers,
/// which is how the sections drifted to different icon and text sizes in
/// the first place.
const double kListIconSize = 44;
const double kListTitleSize = 15;
const double kListSubtitleSize = 12.5;

/// A single "here is something for you" row: icon, title, one line of
/// live detail, and somewhere to go.
///
/// Shared because the recommendations moved from Explore to Home and the
/// obvious alternative — copying the widget across — is how two lists that
/// are supposed to look identical slowly stop matching.
class RecommendationTile extends StatelessWidget {
  final IconData icon;
  final Color accent;
  final String title;
  final String subtitle;
  final VoidCallback onTap;

  const RecommendationTile({
    super.key,
    required this.icon,
    required this.accent,
    required this.title,
    required this.subtitle,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return Material(
      color: ArucadColors.paper,
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        borderRadius: BorderRadius.circular(16),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
          child: Row(children: [
            // Circular, tinted with the row's own accent — the same icon
            // treatment every other row on Home uses, so a list of
            // recommendations reads as one list rather than three widgets.
            Container(
              width: kListIconSize,
              height: kListIconSize,
              decoration: BoxDecoration(
                  color: accent.withValues(alpha: .12),
                  shape: BoxShape.circle),
              child: Icon(icon, color: accent, size: 22),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                          fontWeight: FontWeight.w800,
                          fontSize: kListTitleSize)),
                  const SizedBox(height: 2),
                  Text(subtitle,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                          color: ArucadColors.muted,
                          fontSize: kListSubtitleSize)),
                ],
              ),
            ),
            const Icon(Icons.chevron_right_rounded,
                color: ArucadColors.muted, size: 22),
          ]),
        ),
      ),
    );
  }
}
