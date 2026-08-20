/// A real, working first line of defense against obviously abusive text —
/// keyword-based, checked before a post/comment/review is accepted. This is
/// genuinely functional moderation, not a placeholder, but it is also
/// honestly limited: it catches exact-match slurs/profanity in the list
/// below, nothing more. It cannot see meaning, context, or images — real
/// nudity/violence *image* scanning needs a paid vision-moderation API this
/// prototype has no key for (see README roadmap). Treat this as the "spam
/// and obvious abuse" layer, not a substitute for human moderators.
class ContentModerationException implements Exception {
  final String reason;
  const ContentModerationException(this.reason);
  @override
  String toString() => reason;
}

const _blockedTerms = <String>[
  // Deliberately short and representative rather than exhaustive — a real
  // deployment would use a maintained, much larger list (and ideally a
  // real moderation API) rather than a hardcoded Dart list.
  'orospu',
  'piç',
  'yavşak',
  'ahmak',
  'salak',
  'aptal',
  'nazi',
  'terörist',
];

class ModerationResult {
  final bool allowed;
  final String? reason;
  const ModerationResult.allow()
      : allowed = true,
        reason = null;
  const ModerationResult.block(this.reason) : allowed = false;
}

ModerationResult moderateText(String text) {
  final normalized = text.toLowerCase();
  for (final term in _blockedTerms) {
    if (normalized.contains(term)) {
      return ModerationResult.block(
          'İçerik uygunsuz olabilecek bir ifade içeriyor ("$term"). Lütfen düzenleyip tekrar dene.');
    }
  }
  return const ModerationResult.allow();
}

/// Throws if [text] fails the check — the convenient form for repository
/// methods that just want to reject and let the UI show the reason.
void assertTextAllowed(String text) {
  final result = moderateText(text);
  if (!result.allowed) throw ContentModerationException(result.reason!);
}
