import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// Real "Katıl" confirmation: lets the student pick one of the event's
/// admin-defined participation types (if it has any), then shows what the
/// backend actually did — whether the club-organizer email and the
/// student's own confirmation email were sent — instead of a plain
/// "success" toast that hides real failures. Returns the result the caller
/// (EventCard / EventDetailScreen) uses to update its own "joined" state,
/// or null if the sheet was dismissed without joining.
Future<EventJoinResult?> showEventJoinSheet(
  BuildContext context,
  CampusRepository repository,
  CampusEvent event,
) {
  return showModalBottomSheet<EventJoinResult>(
    context: context,
    isScrollControlled: true,
    builder: (_) => _EventJoinSheet(repository: repository, event: event),
  );
}

class _EventJoinSheet extends StatefulWidget {
  final CampusRepository repository;
  final CampusEvent event;
  const _EventJoinSheet({required this.repository, required this.event});

  @override
  State<_EventJoinSheet> createState() => _EventJoinSheetState();
}

class _EventJoinSheetState extends State<_EventJoinSheet> {
  String? _selectedTypeId;
  bool _submitting = false;
  bool _submittingForm = false;
  String? _error;
  EventJoinResult? _result;

  Future<void> _submit() async {
    setState(() {
      _submitting = true;
      _error = null;
    });
    try {
      final result = await widget.repository
          .joinEvent(widget.event.id, participationTypeId: _selectedTypeId);
      if (!mounted) return;
      setState(() {
        _submitting = false;
        _result = result;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _submitting = false;
        _error = 'Katılım kaydedilemedi. Lütfen tekrar dene.';
      });
    }
  }

  // The real gate (docs/EKSIKLER.md §5): the katılım formu is completed
  // in-app, not just assumed from the email going out — this is what
  // actually makes the join reviewable by the teacher/club in Yoklama.
  Future<void> _submitForm() async {
    setState(() => _submittingForm = true);
    try {
      final result = await widget.repository.submitEventJoinForm(widget.event.id);
      if (!mounted) return;
      setState(() {
        _submittingForm = false;
        _result = result;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _submittingForm = false;
        _error = 'Form kaydedilemedi. Lütfen tekrar dene.';
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final event = widget.event;
    return Padding(
      padding: EdgeInsets.only(
          left: 20, right: 20, top: 20, bottom: MediaQuery.of(context).viewInsets.bottom + 20),
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(_result != null ? 'Katılımın kaydedildi' : 'Etkinliğe Katıl',
                style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
            const SizedBox(height: 4),
            Text(event.title, style: const TextStyle(color: ArucadColors.muted, fontSize: 13)),
            const SizedBox(height: 16),
            if (_result != null) ..._buildStatus(_result!) else ..._buildForm(event),
          ],
        ),
      ),
    );
  }

  List<Widget> _buildForm(CampusEvent event) {
    return [
      if (event.participationTypes.isNotEmpty) ...[
        const Text('Nasıl katılmak istersin?',
            style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13.5)),
        const SizedBox(height: 8),
        Wrap(
          spacing: 6,
          runSpacing: 6,
          children: [
            for (final type in event.participationTypes)
              SelectableChip(
                label: type.label,
                selected: _selectedTypeId == type.id,
                onSelected: (_) => setState(() =>
                    _selectedTypeId = _selectedTypeId == type.id ? null : type.id),
              ),
          ],
        ),
        const SizedBox(height: 16),
      ],
      if (_error != null) ...[
        Text(_error!, style: const TextStyle(color: ArucadColors.danger, fontSize: 13)),
        const SizedBox(height: 8),
      ],
      SizedBox(
        width: double.infinity,
        child: FilledButton(
          onPressed: _submitting ? null : _submit,
          child: Text(_submitting ? 'Kaydediliyor…' : 'Katıl'),
        ),
      ),
    ];
  }

  List<Widget> _buildStatus(EventJoinResult result) {
    return [
      _StatusRow(
        ok: true,
        label: result.alreadyJoined ? 'Zaten katılımcısın' : 'Katılımın kaydedildi',
      ),
      // Emails are only attempted on a fresh join with a real email
      // pipeline (Mock mode has none) — a repeat join doesn't resend
      // anything, so showing "not sent" in either case would misreport a
      // no-op as a failure.
      if (!result.alreadyJoined && result.emailSupported) ...[
        if (widget.event.organizer.isNotEmpty)
          _StatusRow(
            ok: result.clubEmailSent,
            label: result.clubEmailSent
                ? 'Kulübe bilgilendirme e-postası gönderildi'
                : 'Kulübe bilgilendirme e-postası gönderilemedi',
          ),
        _StatusRow(
          ok: result.formEmailSent,
          label: result.formEmailSent
              ? 'Katılım formu e-postana gönderildi'
              : 'Katılım formu e-postası gönderilemedi',
        ),
      ],
      // The form step is real and in-app — completing it here is what
      // actually notifies the organizer that this join is ready to
      // review, instead of just hoping the emailed form gets filled out
      // somewhere else.
      if (!result.formSubmitted) ...[
        _StatusRow(ok: false, label: 'Katılım formu henüz tamamlanmadı'),
        if (_error != null) ...[
          Text(_error!, style: const TextStyle(color: ArucadColors.danger, fontSize: 13)),
          const SizedBox(height: 8),
        ],
        SizedBox(
          width: double.infinity,
          child: OutlinedButton(
            onPressed: _submittingForm ? null : _submitForm,
            child: Text(_submittingForm ? 'Kaydediliyor…' : 'Katılım Formunu Tamamla'),
          ),
        ),
        const SizedBox(height: 8),
      ] else
        _StatusRow(ok: true, label: 'Katılım formu tamamlandı — onay bekleniyor'),
      const SizedBox(height: 16),
      SizedBox(
        width: double.infinity,
        child: FilledButton(
          onPressed: () => Navigator.of(context).pop(_result),
          child: const Text('Tamam'),
        ),
      ),
    ];
  }
}

class _StatusRow extends StatelessWidget {
  final bool ok;
  final String label;
  const _StatusRow({required this.ok, required this.label});

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 8),
        child: Row(children: [
          Icon(ok ? Icons.check_circle : Icons.error_outline,
              size: 18, color: ok ? ArucadColors.success : ArucadColors.warning),
          const SizedBox(width: 8),
          Expanded(child: Text(label, style: const TextStyle(fontSize: 13.5))),
        ]),
      );
}
