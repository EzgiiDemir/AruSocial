import 'dart:async';

import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/models/staff_application.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

class ApplyChoice {
  final String id;
  final String label;
  const ApplyChoice({required this.id, required this.label});
}

const _completeByEmailMessage =
    'Başvurunuz ilgili departman başkanına iletilmiştir. Katılımınızı '
    'tamamlamak için e-posta adresinize gönderilen katılım formunu '
    'doldurmanız gerekmektedir.';

String _applyTargetId(String targetType, String targetId) {
  if (targetType == 'help' || targetType == 'service') {
    return campusServiceIdKey(targetId);
  }
  return targetId;
}

bool _sameTarget(ParticipationApplication a, String targetType, String targetId) {
  if (a.targetType != targetType) return false;
  if (a.targetId == targetId) return true;
  if (targetType == 'help' || targetType == 'service') {
    return campusServiceIdsMatch(a.targetId, targetId);
  }
  return false;
}

String _errorText(ApiClientException e) => switch (e.code) {
      'TARGET_NOT_FOUND' => 'Bu kayıt henüz sistemde yok. Keşfet’i yenileyip tekrar dene.',
      'ALREADY_APPLIED' => 'Bu hedef için açık bir başvurunuz var.',
      'ALREADY_APPROVED' => 'Bu hedef için zaten onaylandınız.',
      'ALREADY_MEMBER' => 'Bu kulübün üyesisiniz.',
      _ => e.message,
    };

/// Katıl / Başvur applies immediately — no preview question form is shown
/// anymore (product decision: the short "ön başvuru" Q&A step is retired).
/// Tapping the button submits the Preview stage right away with no answers
/// and shows a confirmation notification; the real application is
/// completed by the student through the Detail form emailed to them, never
/// inside this flow.
///
/// Event participation types in [extraChoices] are an exception: they are
/// a real catalog choice the backend stores on the application (and later
/// on EventJoin), so when they are provided this sheet asks for one
/// before submitting.
///
/// Returns whether the caller should treat this as "joined" now (see
/// [ParticipationApplication.countsAsJoined]).
Future<bool> showApplyBottomSheet(
  BuildContext context, {
  required CampusRepository repository,
  required String targetType,
  required String targetId,
  required String targetLabel,
  String? externalUrl,
  List<ApplyChoice> extraChoices = const [],
  String extraChoicePayloadKey = 'participationTypeId',
}) async {
  final resolvedTargetId = _applyTargetId(targetType, targetId);

  Map<String, dynamic> formPayload = const {};
  if (extraChoices.isNotEmpty) {
    final picked = await showModalBottomSheet<String>(
      context: context,
      builder: (ctx) => SafeArea(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 24),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Text('Nasıl katılmak istiyorsun?',
                  style: Theme.of(ctx)
                      .textTheme
                      .titleMedium
                      ?.copyWith(fontWeight: FontWeight.w900)),
              const SizedBox(height: 12),
              for (final choice in extraChoices)
                Padding(
                  padding: const EdgeInsets.only(bottom: 8),
                  child: FilledButton.tonal(
                    onPressed: () => Navigator.pop(ctx, choice.id),
                    child: Text(choice.label),
                  ),
                ),
              TextButton(
                onPressed: () => Navigator.pop(ctx),
                child: const Text('Vazgeç'),
              ),
            ],
          ),
        ),
      ),
    );
    if (picked == null) return false;
    if (!context.mounted) return false;
    formPayload = {extraChoicePayloadKey: picked};
  }

  unawaited(showDialog<void>(
    context: context,
    barrierDismissible: false,
    builder: (_) => const Center(
      child: SizedBox(width: 36, height: 36, child: CircularProgressIndicator(strokeWidth: 3)),
    ),
  ));

  IconData icon;
  Color iconColor;
  String title;
  String message;
  var joined = false;

  try {
    final mine = await repository.getMyApplications();
    final matches = mine.where((a) => _sameTarget(a, targetType, resolvedTargetId)).toList();
    final current = matches.isEmpty ? null : matches.first;

    if (current == null || current.canResubmitPreview) {
      final created = await repository.submitApplication(
        targetType: targetType,
        targetId: resolvedTargetId,
        formPayload: formPayload,
      );
      icon = Icons.check_circle;
      iconColor = ArucadColors.success;
      title = 'Başvurunuz Alındı ✓';
      message = _completeByEmailMessage;
      joined = created.countsAsJoined;
    } else if (current.isApproved) {
      icon = Icons.verified_outlined;
      iconColor = ArucadColors.success;
      title = 'Zaten Katılımcısınız';
      message = '"$targetLabel" için başvurunuz daha önce onaylandı.';
      joined = true;
    } else if (current.needsRevision) {
      icon = Icons.edit_note_outlined;
      iconColor = ArucadColors.warning;
      title = 'Revizyon Bekleniyor';
      message = 'Başvurunuzda revizyon isteniyor — e-postanıza gönderilen '
          'formu güncelleyip tekrar gönderin.'
          '${(current.reviewNote ?? '').isEmpty ? '' : '\n\n"${current.reviewNote}"'}';
      joined = false;
    } else if (current.status == ParticipationApplication.statusDetailFormPending) {
      icon = Icons.mark_email_unread_outlined;
      iconColor = ArucadColors.primary;
      title = 'Başvurunuz Alındı ✓';
      message = _completeByEmailMessage;
      joined = true;
    } else {
      icon = Icons.hourglass_top_outlined;
      iconColor = ArucadColors.primary;
      title = 'Başvurunuz İnceleniyor';
      message = '"$targetLabel" için başvurunuz ${current.responsibleStaffName ?? 'ilgili birim'} '
          'tarafından inceleniyor. Karar uygulama bildirimi ve e-posta ile gelecek.';
      joined = true;
    }
  } on ApiClientException catch (e) {
    icon = Icons.error_outline;
    iconColor = ArucadColors.danger;
    title = 'Başvuru Gönderilemedi';
    message = _errorText(e);
  } catch (_) {
    icon = Icons.error_outline;
    iconColor = ArucadColors.danger;
    title = 'Başvuru Gönderilemedi';
    message = 'Bir şeyler ters gitti. Lütfen tekrar dene.';
  }

  if (!context.mounted) return joined;
  Navigator.of(context, rootNavigator: true).pop();

  await showDialog<void>(
    context: context,
    builder: (ctx) => AlertDialog(
      icon: Icon(icon, color: iconColor, size: 40),
      title: Text(title, textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w900)),
      content: Text(message, textAlign: TextAlign.center, style: const TextStyle(height: 1.4)),
      actionsAlignment: MainAxisAlignment.center,
      actions: [
        FilledButton(onPressed: () => Navigator.of(ctx).pop(), child: const Text('Tamam')),
        if (externalUrl != null && externalUrl.isNotEmpty)
          OutlinedButton.icon(
            onPressed: () => launchUrl(Uri.parse(externalUrl)),
            icon: const Icon(Icons.open_in_new, size: 16),
            label: const Text('İlana Git'),
          ),
      ],
    ),
  );

  return joined;
}
