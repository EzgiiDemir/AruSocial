import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// Contest a moderation decision about your own content.
///
/// The decision is made by a person on the server. Nothing here — and
/// nothing on the route it calls — re-runs the classifier that made the
/// original call, because answering an appeal with the same model is the
/// same answer with extra steps.
Future<bool> showAppealSheet(
  BuildContext context, {
  required String contentLabel,
  required Future<void> Function(String reason) onSubmit,
}) async {
  final submitted = await showModalBottomSheet<bool>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.transparent,
    builder: (_) => _AppealSheet(contentLabel: contentLabel, onSubmit: onSubmit),
  );

  return submitted ?? false;
}

class _AppealSheet extends StatefulWidget {
  final String contentLabel;
  final Future<void> Function(String reason) onSubmit;

  const _AppealSheet({required this.contentLabel, required this.onSubmit});

  @override
  State<_AppealSheet> createState() => _AppealSheetState();
}

class _AppealSheetState extends State<_AppealSheet> {
  final _reasonC = TextEditingController();
  bool _sending = false;
  String? _error;

  @override
  void dispose() {
    _reasonC.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    final strings = AppLocale.of(context);
    final reason = _reasonC.text.trim();

    if (reason.isEmpty) {
      setState(() => _error = strings.t('appeal_reason_required'));

      return;
    }
    if (_sending) return;

    setState(() {
      _sending = true;
      _error = null;
    });

    try {
      await widget.onSubmit(reason);
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } catch (e) {
      if (!mounted) return;
      // The sheet stays open and keeps the text. Someone writing an
      // appeal has usually thought about the wording, and losing it to a
      // dropped connection is its own small injustice.
      final text = e.toString();
      setState(() {
        _sending = false;
        _error = text.contains('409') || text.contains('already')
            ? strings.t('appeal_already_submitted')
            : strings.t('appeal_error_generic');
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final scheme = Theme.of(context).colorScheme;

    return Padding(
      padding: EdgeInsets.only(bottom: MediaQuery.of(context).viewInsets.bottom),
      child: Container(
        decoration: BoxDecoration(
          color: scheme.surface,
          borderRadius: const BorderRadius.vertical(top: Radius.circular(22)),
        ),
        child: SafeArea(
          top: false,
          child: Padding(
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 16),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              Container(
                width: 38,
                height: 4,
                decoration: BoxDecoration(
                  color: ArucadColors.border,
                  borderRadius: BorderRadius.circular(999),
                ),
              ),
              const SizedBox(height: 16),
              Align(
                alignment: Alignment.centerLeft,
                child: Text(strings.t('appeal_title'),
                    style: const TextStyle(
                        fontWeight: FontWeight.w900, fontSize: 19)),
              ),
              const SizedBox(height: 4),
              Align(
                alignment: Alignment.centerLeft,
                child: Text(
                  widget.contentLabel,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style:
                      const TextStyle(color: ArucadColors.muted, fontSize: 13),
                ),
              ),
              const SizedBox(height: 16),
              TextField(
                controller: _reasonC,
                enabled: !_sending,
                maxLines: 4,
                maxLength: 1000,
                autofocus: true,
                decoration: InputDecoration(
                  labelText: strings.t('appeal_reason_label'),
                  hintText: strings.t('appeal_reason_hint'),
                  filled: true,
                  fillColor: ArucadColors.mist,
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(14),
                    borderSide: BorderSide.none,
                  ),
                ),
              ),
              if (_error != null)
                Align(
                  alignment: Alignment.centerLeft,
                  child: Text(_error!,
                      style: const TextStyle(
                          color: ArucadColors.red,
                          fontSize: 13,
                          fontWeight: FontWeight.w600)),
                ),
              const SizedBox(height: 12),
              Row(children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed:
                        _sending ? null : () => Navigator.of(context).pop(false),
                    child: Text(strings.t('social_cancel')),
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: FilledButton(
                    onPressed: _sending ? null : _submit,
                    child: _sending
                        ? const SizedBox(
                            width: 16,
                            height: 16,
                            child: CircularProgressIndicator(strokeWidth: 2))
                        : Text(strings.t('appeal_submit')),
                  ),
                ),
              ]),
            ]),
          ),
        ),
      ),
    );
  }
}
