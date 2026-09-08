import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/survey.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// Checks for a real, currently-active survey the student hasn't voted on
/// yet, and shows it as a popup if one exists — the "hızlı anket" feature.
///
/// [silent] separates the two callers, which want opposite things when
/// there is nothing to show. Home calls this automatically on first load,
/// where "no surveys right now" is the normal case and a snackbar about it
/// would be noise on every single launch. Tapping the feedback card is a
/// deliberate question, so there it does deserve an answer.
Future<void> maybeShowSurveyPopup(
  BuildContext context,
  CampusRepository repository, {
  bool silent = false,
}) async {
  try {
    final surveys = await repository.getActiveSurveys();
    if (!context.mounted) return;
    final unvoted = surveys.where((s) => !s.hasVoted).toList();
    if (unvoted.isEmpty) {
      if (!silent) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(
            content: Text(AppLocale.of(context).t('home_feedback_none'))));
      }
      return;
    }
    await showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      isDismissible: true,
      builder: (ctx) => _SurveySheet(repository: repository, survey: unvoted.first),
    );
  } catch (_) {
    if (!context.mounted || silent) return;
    ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(AppLocale.of(context).t('home_feedback_error'))));
  }
}

class _SurveySheet extends StatefulWidget {
  final CampusRepository repository;
  final Survey survey;
  const _SurveySheet({required this.repository, required this.survey});

  @override
  State<_SurveySheet> createState() => _SurveySheetState();
}

class _SurveySheetState extends State<_SurveySheet> {
  final Set<String> _selected = {};
  bool _submitting = false;
  Survey? _result;

  Future<void> _vote() async {
    if (_selected.isEmpty || _submitting) return;
    setState(() => _submitting = true);
    try {
      final result = await widget.repository.voteSurvey(widget.survey.id, _selected.toList());
      if (!mounted) return;
      setState(() => _result = result);
    } catch (_) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Oy gönderilemedi. Seçimini koruduk, tekrar deneyebilirsin.')));
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final survey = _result ?? widget.survey;
    return Padding(
      padding: EdgeInsets.only(
          left: 20, right: 20, top: 20, bottom: MediaQuery.of(context).viewInsets.bottom + 20),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              const Icon(Icons.poll_outlined, color: ArucadColors.primary),
              const SizedBox(width: 8),
              const Text('Hızlı Anket',
                  style: TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
            ]),
            const SizedBox(height: 12),
            Text(survey.question, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15)),
            if (survey.description != null && survey.description!.isNotEmpty) ...[
              const SizedBox(height: 4),
              Text(survey.description!, style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5)),
            ],
            const SizedBox(height: 16),
            if (_result == null) ..._buildOptions() else ..._buildResults(survey),
          ],
        ),
      ),
    );
  }

  List<Widget> _buildOptions() {
    final survey = widget.survey;
    return [
      for (final option in survey.options)
        InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: () => setState(() {
            if (survey.multipleChoice) {
              _selected.contains(option.id) ? _selected.remove(option.id) : _selected.add(option.id);
            } else {
              _selected
                ..clear()
                ..add(option.id);
            }
          }),
          child: Padding(
            padding: const EdgeInsets.symmetric(vertical: 8),
            child: Row(children: [
              Icon(
                _selected.contains(option.id)
                    ? (survey.multipleChoice ? Icons.check_box : Icons.radio_button_checked)
                    : (survey.multipleChoice
                        ? Icons.check_box_outline_blank
                        : Icons.radio_button_unchecked),
                color: _selected.contains(option.id) ? ArucadColors.primary : ArucadColors.muted,
                size: 20,
              ),
              const SizedBox(width: 10),
              Expanded(child: Text(option.label, style: const TextStyle(fontSize: 14))),
            ]),
          ),
        ),
      const SizedBox(height: 16),
      SizedBox(
        width: double.infinity,
        child: FilledButton(
          onPressed: _selected.isEmpty || _submitting ? null : _vote,
          child: Text(_submitting ? 'Gönderiliyor…' : 'Oyla'),
        ),
      ),
    ];
  }

  List<Widget> _buildResults(Survey result) {
    if (!result.showResults) {
      return [
        const Text('Oyun kaydedildi. Sonuçlar bu anket için gösterilmiyor.',
            style: TextStyle(color: ArucadColors.muted)),
        const SizedBox(height: 16),
        SizedBox(
          width: double.infinity,
          child: FilledButton(
            onPressed: () => Navigator.of(context).pop(),
            child: const Text('Kapat'),
          ),
        ),
      ];
    }
    return [
      for (final option in result.options)
        Padding(
          padding: const EdgeInsets.only(bottom: 10),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Expanded(
                child: Text(option.label,
                    style: TextStyle(
                        fontSize: 13.5,
                        fontWeight: result.myOptionIds.contains(option.id)
                            ? FontWeight.w800
                            : FontWeight.w500)),
              ),
              Text('${option.percentage.toStringAsFixed(0)}%',
                  style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5)),
            ]),
            const SizedBox(height: 4),
            ClipRRect(
              borderRadius: BorderRadius.circular(999),
              child: LinearProgressIndicator(
                value: option.percentage / 100,
                minHeight: 8,
                backgroundColor: ArucadColors.mist,
                color: result.myOptionIds.contains(option.id)
                    ? ArucadColors.primary
                    : ArucadColors.slate,
              ),
            ),
          ]),
        ),
      const SizedBox(height: 8),
      Text('${result.totalVotes} oy', style: const TextStyle(color: ArucadColors.muted, fontSize: 11.5)),
      const SizedBox(height: 16),
      SizedBox(
        width: double.infinity,
        child: FilledButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Kapat'),
        ),
      ),
    ];
  }
}
