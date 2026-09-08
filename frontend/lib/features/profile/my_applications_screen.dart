import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/staff_application.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_back_button.dart';

/// Student-facing inbox for every Katıl/Başvur the account has submitted.
/// Staff/admin review lives in the Admin/Trainer panels; this is the other
/// half of that lifecycle so a student can see status without guessing.
class MyApplicationsScreen extends StatefulWidget {
  final CampusRepository repository;
  const MyApplicationsScreen({super.key, required this.repository});

  @override
  State<MyApplicationsScreen> createState() => _MyApplicationsScreenState();
}

class _MyApplicationsScreenState extends State<MyApplicationsScreen> {
  bool _loading = true;
  String? _error;
  List<ParticipationApplication> _rows = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _cancel(ParticipationApplication application) async {
    final label = application.targetLabel ?? application.targetId;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Başvurunu iptal et'),
        content: Text(
            '“$label” başvurun iptal edilsin mi? Bu işlem geri alınamaz, '
            'ancak sonra tekrar başvurabilirsin.'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx, false),
              child: const Text('Vazgeç')),
          FilledButton(
              onPressed: () => Navigator.pop(ctx, true),
              child: const Text('İptal et')),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;

    try {
      await widget.repository.cancelApplication(application.id);
      await _load();
    } on ApiClientException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final rows = await widget.repository.getMyApplications();
      if (!mounted) return;
      setState(() {
        _rows = rows;
        _loading = false;
      });
    } on ApiClientException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e.message;
        _loading = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _error = AppLocale.of(context).t('apps_load_failed');
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final s = AppLocale.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(s.t('apps_title')), leading: const CampusBackButton()),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: Column(mainAxisSize: MainAxisSize.min, children: [
                      Text(_error!, textAlign: TextAlign.center),
                      const SizedBox(height: 12),
                      FilledButton(onPressed: _load, child: Text(s.t('common_retry'))),
                    ]),
                  ),
                )
              : RefreshIndicator(
                  onRefresh: _load,
                  child: _rows.isEmpty
                      ? ListView(children: [
                          const SizedBox(height: 80),
                          Center(
                            child: Text(s.t('apps_empty'),
                                style: TextStyle(
                                    color: Theme.of(context)
                                        .colorScheme
                                        .onSurfaceVariant)),
                          ),
                        ])
                      : ListView.separated(
                          padding: const EdgeInsets.fromLTRB(16, 12, 16, 32),
                          itemCount: _rows.length,
                          separatorBuilder: (_, __) => const SizedBox(height: 8),
                          itemBuilder: (context, index) {
                            return _ApplicationCard(
                              application: _rows[index],
                              onCancel: () => _cancel(_rows[index]),
                            );
                          },
                        ),
                ),
    );
  }
}

/// One application, showing where it stands and why.
///
/// The old card offered a link to the detail form. That link is gone: the
/// form arrives by email with its own token, and duplicating it here meant
/// two places to keep in sync and a student unsure which one counted. What
/// belongs on this screen is the answer to "what happened to my
/// application" — the status, the reason when there is one, and a way out
/// while it is still open.
class _ApplicationCard extends StatelessWidget {
  final ParticipationApplication application;
  final VoidCallback onCancel;

  const _ApplicationCard({required this.application, required this.onCancel});

  /// Colour carries the outcome, so the state is readable before the words.
  (Color, IconData) get _tone => switch (application.status) {
        ParticipationApplication.statusApproved => (
            ArucadColors.campusGreen,
            Icons.check_circle_outline,
          ),
        ParticipationApplication.statusRejected => (
            ArucadColors.red,
            Icons.cancel_outlined,
          ),
        ParticipationApplication.statusCancelled => (
            ArucadColors.muted,
            Icons.remove_circle_outline,
          ),
        _ => (ArucadColors.primary, Icons.hourglass_top_outlined),
      };

  @override
  Widget build(BuildContext context) {
    final (tone, icon) = _tone;
    final note = application.reviewNote?.trim() ?? '';
    // Cancelling is only offered while the outcome is still undecided; the
    // server refuses it afterwards, and showing a button that cannot work
    // is worse than not showing one.
    final cancellable = application.isOpen;

    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(14, 12, 14, 8),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(children: [
              Icon(icon, size: 20, color: tone),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  application.targetLabel ?? application.targetId,
                  style: const TextStyle(
                      fontWeight: FontWeight.w800, fontSize: 15),
                ),
              ),
              Container(
                padding:
                    const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: tone.withValues(alpha: .12),
                  borderRadius: BorderRadius.circular(999),
                ),
                child: Text(application.statusLabel,
                    style: TextStyle(
                        color: tone,
                        fontSize: 11.5,
                        fontWeight: FontWeight.w800)),
              ),
            ]),
            if (application.responsibleStaffName != null) ...[
              const SizedBox(height: 8),
              Text('Sorumlu: ${application.responsibleStaffName}',
                  style: const TextStyle(
                      color: ArucadColors.muted, fontSize: 12)),
            ],
            // The reason matters most on a rejection: "Reddedildi" with no
            // explanation leaves nothing to act on.
            if (note.isNotEmpty) ...[
              const SizedBox(height: 8),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.all(10),
                decoration: BoxDecoration(
                  color: tone.withValues(alpha: .06),
                  borderRadius: BorderRadius.circular(10),
                ),
                child: Text(note,
                    style: const TextStyle(fontSize: 12.5, height: 1.4)),
              ),
            ],
            if (application.awaitingStudentDetail) ...[
              const SizedBox(height: 8),
              const Text(
                'Detaylı başvuru formu e-posta adresine gönderildi. '
                'Başvurunun ilerlemesi için formu doldurman gerekiyor.',
                style: TextStyle(fontSize: 12.5, height: 1.4),
              ),
            ],
            if (cancellable)
              Align(
                alignment: Alignment.centerRight,
                child: TextButton.icon(
                  onPressed: onCancel,
                  icon: const Icon(Icons.close_rounded, size: 16),
                  label: const Text('Başvuruyu iptal et'),
                  style: TextButton.styleFrom(foregroundColor: ArucadColors.red),
                ),
              )
            else
              const SizedBox(height: 4),
          ],
        ),
      ),
    );
  }
}
