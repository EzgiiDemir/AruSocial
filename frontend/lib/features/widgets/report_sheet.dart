import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/report_reason.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// What the caller sends to the API once a reason is picked.
class ReportSubmission {
  final ReportReason reason;

  /// Optional free text. Moderated server-side like any other user text —
  /// the report form has been used as an abuse channel of its own.
  final String description;

  const ReportSubmission({required this.reason, required this.description});
}

/// The one report sheet, used from every surface.
///
/// Reporting a post, a comment, a story, a place or a person is the same
/// interaction with a different target, and giving each screen its own
/// dialog is how the backend ended up with five different report shapes.
/// Callers supply what is being reported and a submit function; the sheet
/// owns everything else.
Future<bool> showReportSheet(
  BuildContext context, {
  required String targetLabel,
  required Future<void> Function(ReportSubmission) onSubmit,
}) async {
  final sent = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.transparent,
    builder: (_) => _ReportSheet(targetLabel: targetLabel, onSubmit: onSubmit),
  );

  return sent ?? false;
}

class _ReportSheet extends StatefulWidget {
  final String targetLabel;
  final Future<void> Function(ReportSubmission) onSubmit;

  const _ReportSheet({required this.targetLabel, required this.onSubmit});

  @override
  State<_ReportSheet> createState() => _ReportSheetState();
}

class _ReportSheetState extends State<_ReportSheet> {
  ReportReason? _reason;
  final _descriptionC = TextEditingController();
  bool _sending = false;
  String? _error;

  @override
  void dispose() {
    _descriptionC.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final reason = _reason;
    if (reason == null || _sending) return;

    setState(() {
      _sending = true;
      _error = null;
    });

    try {
      await widget.onSubmit(ReportSubmission(
        reason: reason,
        description: _descriptionC.text.trim(),
      ));
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } catch (e) {
      if (!mounted) return;
      // Stay open on failure. Closing the sheet and showing a toast loses
      // what they typed and makes it look like the report went through.
      setState(() {
        _sending = false;
        _error = _messageFor(e);
      });
    }
  }

  String _messageFor(Object error) {
    final text = error.toString();
    final strings = AppLocale.of(context);
    if (text.contains('CONTENT_BLOCKED')) {
      return strings.t('report_error_description_blocked');
    }
    if (text.contains('429') || text.toLowerCase().contains('too many')) {
      return strings.t('report_error_rate_limited');
    }

    return strings.t('report_error_generic');
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final scheme = Theme.of(context).colorScheme;

    return Padding(
      // Keeps the send button above the keyboard while the description
      // field has focus.
      padding:
          EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: DraggableScrollableSheet(
        initialChildSize: .78,
        minChildSize: .5,
        maxChildSize: .95,
        expand: false,
        // Material, not a Container with a BoxDecoration: the reason tiles
        // paint their ink splash onto the nearest Material ancestor, and a
        // decorated box between the two hides the press feedback entirely.
        // Flutter asserts on exactly this, which is how it was caught.
        builder: (context, controller) => Material(
          color: scheme.surface,
          borderRadius: const BorderRadius.vertical(top: Radius.circular(22)),
          clipBehavior: Clip.antiAlias,
          child: Column(children: [
            const SizedBox(height: 10),
            Container(
              width: 38,
              height: 4,
              decoration: BoxDecoration(
                color: ArucadColors.border,
                borderRadius: BorderRadius.circular(999),
              ),
            ),
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 14, 20, 6),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(strings.t('report_title'),
                      style: const TextStyle(
                          fontWeight: FontWeight.w900, fontSize: 19)),
                  const SizedBox(height: 4),
                  Text(
                    widget.targetLabel,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                        color: ArucadColors.muted, fontSize: 13),
                  ),
                ],
              ),
            ),
            Expanded(
              child: ListView(
                controller: controller,
                padding: const EdgeInsets.fromLTRB(12, 4, 12, 12),
                children: [
                  RadioGroup<ReportReason>(
                    groupValue: _reason,
                    // RadioGroup requires a non-null handler, so the
                    // in-flight guard lives inside it: a second tap while
                    // the report is being sent must not change what gets
                    // submitted.
                    onChanged: (value) {
                      if (_sending) return;
                      setState(() => _reason = value);
                    },
                    child: Column(children: [
                      for (final reason in ReportReason.values)
                        RadioListTile<ReportReason>(
                          value: reason,
                          activeColor: ArucadColors.primary,
                          title: Text(reason.label(strings),
                              style: const TextStyle(
                                  fontWeight: FontWeight.w700, fontSize: 14.5)),
                          subtitle: Text(reason.hint(strings),
                              style: const TextStyle(
                                  color: ArucadColors.muted, fontSize: 12.5)),
                        ),
                    ]),
                  ),
                  Padding(
                    padding: const EdgeInsets.fromLTRB(8, 10, 8, 0),
                    child: TextField(
                      controller: _descriptionC,
                      enabled: !_sending,
                      maxLines: 3,
                      maxLength: 500,
                      decoration: InputDecoration(
                        labelText: strings.t('report_description_label'),
                        hintText: strings.t('report_description_hint'),
                        filled: true,
                        fillColor: ArucadColors.mist,
                        border: OutlineInputBorder(
                          borderRadius: BorderRadius.circular(14),
                          borderSide: BorderSide.none,
                        ),
                      ),
                    ),
                  ),
                ],
              ),
            ),
            SafeArea(
              top: false,
              child: Padding(
                padding: const EdgeInsets.fromLTRB(20, 6, 20, 14),
                child: Column(mainAxisSize: MainAxisSize.min, children: [
                  // Pinned next to the button rather than left in the
                  // scrolling list: an error the reporter has to scroll
                  // back up to find reads as "nothing happened", which is
                  // exactly the wrong impression after a failed report.
                  if (_error != null)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 10),
                      child: Text(_error!,
                          textAlign: TextAlign.center,
                          style: const TextStyle(
                              color: ArucadColors.red,
                              fontSize: 13,
                              fontWeight: FontWeight.w600)),
                    ),
                  Row(children: [
                    Expanded(
                      child: OutlinedButton(
                        onPressed: _sending
                            ? null
                            : () => Navigator.of(context).pop(false),
                        child: Text(strings.t('social_cancel')),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: FilledButton(
                        // Disabled until a reason is chosen: the code is what
                        // the queue sorts on, so "send anyway" would just
                        // recreate the untriageable pile of `other`.
                        onPressed:
                            (_reason == null || _sending) ? null : _submit,
                        child: _sending
                            ? const SizedBox(
                                width: 16,
                                height: 16,
                                child:
                                    CircularProgressIndicator(strokeWidth: 2))
                            : Text(strings.t('social_send')),
                      ),
                    ),
                  ]),
                ]),
              ),
            ),
          ]),
        ),
      ),
    );
  }
}

/// Confirmation after a report is accepted.
///
/// A welfare concern gets different words: someone reporting a friend in
/// crisis should not be thanked for flagging a violation.
void showReportSentMessage(BuildContext context, ReportReason reason) {
  final strings = AppLocale.of(context);

  ScaffoldMessenger.of(context).showSnackBar(SnackBar(
    content: Text(reason.isWelfareConcern
        ? strings.t('report_sent_welfare')
        : strings.t('report_sent')),
    duration: const Duration(seconds: 4),
  ));
}
