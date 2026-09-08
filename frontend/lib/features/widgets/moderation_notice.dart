import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// Tells someone their upload was refused, and why.
///
/// A snackbar is the wrong shape for this. It disappears after a few
/// seconds, it is easy to miss while the screen is still settling, and the
/// message it has to carry — what was wrong, that it was not published, and
/// that repeating it costs the account — does not fit in one line. Someone
/// who never registers why their photo vanished simply tries again, which
/// is how a first warning becomes a ban nobody saw coming.
///
/// The wording comes from the server. The client does not decide what
/// counts as a violation, and must not paraphrase it into something softer.
Future<void> showModerationNotice(
  BuildContext context, {
  required String message,
  String? code,
}) {
  final banned = code == 'ACCOUNT_SUSPENDED' || code == 'ACCOUNT_BANNED';
  final held = code == 'MODERATION_UNAVAILABLE';

  final (icon, tint, title) = switch (true) {
    _ when banned => (
        Icons.gpp_maybe_outlined,
        ArucadColors.red,
        'Hesabın askıya alındı',
      ),
    _ when held => (
        Icons.hourglass_top_outlined,
        ArucadColors.primary,
        'İncelemeye alındı',
      ),
    _ => (
        Icons.block_outlined,
        ArucadColors.red,
        'Bu içerik paylaşılamadı',
      ),
  };

  return showDialog<void>(
    context: context,
    builder: (ctx) => AlertDialog(
      icon: Icon(icon, color: tint, size: 32),
      title: Text(title,
          textAlign: TextAlign.center,
          style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(message,
              textAlign: TextAlign.center,
              style: const TextStyle(fontSize: 13.5, height: 1.5)),
          if (!banned && !held) ...[
            const SizedBox(height: 14),
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: tint.withValues(alpha: .07),
                borderRadius: BorderRadius.circular(12),
              ),
              child: const Text(
                'Çıplaklık, cinsel içerik, şiddet, kan, nefret söylemi ve '
                'taciz içeren görsel veya videolar ARUVERSE’te paylaşılamaz. '
                'Paylaşmadan önce içeriğini kontrol et.',
                textAlign: TextAlign.center,
                style: TextStyle(fontSize: 12, height: 1.45),
              ),
            ),
          ],
        ],
      ),
      actions: [
        FilledButton(
          onPressed: () => Navigator.pop(ctx),
          child: const Text('Anladım'),
        ),
      ],
    ),
  );
}

/// Turns an upload/publish failure into the notice above.
///
/// Returns true when it handled the error, so callers can fall back to
/// their own messaging for everything that is not a moderation decision.
Future<bool> showModerationNoticeFor(BuildContext context, Object error) async {
  if (error is! ApiClientException) return false;

  const handled = {
    'CONTENT_BLOCKED',
    'ACCOUNT_SUSPENDED',
    'ACCOUNT_BANNED',
    'MODERATION_UNAVAILABLE',
  };
  if (!handled.contains(error.code)) return false;

  await showModerationNotice(context, message: error.message, code: error.code);

  return true;
}
