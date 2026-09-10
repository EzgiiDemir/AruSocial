import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// What the backend decided about a piece of media.
///
/// Flutter is not a security boundary and this enum is not one either: it
/// exists to *describe* a decision the server already made, never to make
/// one. Nothing in the app may infer "safe" from a missing or unknown
/// status — [fromApi] deliberately maps anything it does not recognise to
/// [pending] rather than to [published], so a value added on the server
/// tomorrow is treated as "still being checked" rather than shown.
enum ModerationState {
  /// Uploaded, stored privately, waiting for a verdict.
  pending,

  /// A human is looking at it. Private meanwhile.
  review,

  /// A verdict was reached and it is public.
  published,

  /// Refused. Never displayed.
  blocked,

  /// Nothing could inspect it. Held, and not the user's fault.
  moderationError,

  /// Taken down after publication.
  removed;

  static ModerationState fromApi(String? raw) => switch (raw) {
        'approved' || 'published' => ModerationState.published,
        'review' => ModerationState.review,
        'blocked' || 'rejected' => ModerationState.blocked,
        'moderation_error' => ModerationState.moderationError,
        'removed' => ModerationState.removed,
        // Includes 'pending', null, and anything this build has not seen.
        _ => ModerationState.pending,
      };

  bool get isVisibleToOthers => this == ModerationState.published;

  /// Whether the owner should still see their own item, greyed out with
  /// an explanation. Blocked content is not shown back to anyone.
  bool get ownerCanSee => this != ModerationState.blocked;

  /// Short label for a badge.
  String label(AppStrings strings) => switch (this) {
        ModerationState.pending => strings.t('mod_state_pending'),
        ModerationState.review => strings.t('mod_state_review'),
        ModerationState.published => strings.t('mod_state_published'),
        ModerationState.blocked => strings.t('mod_state_blocked'),
        ModerationState.moderationError => strings.t('mod_state_error'),
        ModerationState.removed => strings.t('mod_state_removed'),
      };

  /// A sentence the owner can act on. Deliberately never mentions a score
  /// or a model — "sexual content" is a reason someone can understand and
  /// appeal; "nsfw = 0.9341" is not.
  String explanation(AppStrings strings) => switch (this) {
        ModerationState.pending => strings.t('mod_explain_pending'),
        ModerationState.review => strings.t('mod_explain_review'),
        ModerationState.published => strings.t('mod_explain_published'),
        ModerationState.blocked => strings.t('mod_explain_blocked'),
        ModerationState.moderationError => strings.t('mod_explain_error'),
        ModerationState.removed => strings.t('mod_explain_removed'),
      };

  Color get accent => switch (this) {
        ModerationState.published => ArucadColors.campusGreen,
        ModerationState.pending || ModerationState.review => ArucadColors.yellow,
        ModerationState.moderationError => ArucadColors.muted,
        ModerationState.blocked || ModerationState.removed => ArucadColors.red,
      };

  IconData get icon => switch (this) {
        ModerationState.published => Icons.check_circle_outline_rounded,
        ModerationState.pending => Icons.hourglass_empty_rounded,
        ModerationState.review => Icons.visibility_outlined,
        ModerationState.moderationError => Icons.cloud_off_rounded,
        ModerationState.blocked => Icons.block_rounded,
        ModerationState.removed => Icons.delete_outline_rounded,
      };
}

/// Compact badge showing an item's moderation state.
class ModerationStateBadge extends StatelessWidget {
  final ModerationState state;

  /// Published content usually needs no badge — a green tick on every
  /// ordinary photo is noise. Set true where the state is the point.
  final bool showWhenPublished;

  const ModerationStateBadge({
    super.key,
    required this.state,
    this.showWhenPublished = false,
  });

  @override
  Widget build(BuildContext context) {
    if (state == ModerationState.published && !showWhenPublished) {
      return const SizedBox.shrink();
    }
    final strings = AppLocale.of(context);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
      decoration: BoxDecoration(
        color: state.accent.withValues(alpha: .12),
        borderRadius: BorderRadius.circular(999),
      ),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(state.icon, size: 13, color: state.accent),
        const SizedBox(width: 5),
        Text(
          state.label(strings),
          style: TextStyle(
            color: state.accent,
            fontWeight: FontWeight.w700,
            fontSize: 11.5,
          ),
        ),
      ]),
    );
  }
}

/// Full-width explanation for the owner of a held item.
class ModerationStateNotice extends StatelessWidget {
  final ModerationState state;

  const ModerationStateNotice({super.key, required this.state});

  @override
  Widget build(BuildContext context) {
    if (state == ModerationState.published) return const SizedBox.shrink();
    final strings = AppLocale.of(context);

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: state.accent.withValues(alpha: .08),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: state.accent.withValues(alpha: .25)),
      ),
      child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Icon(state.icon, size: 18, color: state.accent),
        const SizedBox(width: 10),
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(state.label(strings),
                  style: TextStyle(
                      color: state.accent,
                      fontWeight: FontWeight.w800,
                      fontSize: 13)),
              const SizedBox(height: 2),
              Text(state.explanation(strings),
                  style: const TextStyle(
                      color: ArucadColors.muted, fontSize: 12.5, height: 1.35)),
            ],
          ),
        ),
      ]),
    );
  }
}
