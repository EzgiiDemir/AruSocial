import 'package:flutter/material.dart';
import 'package:collection/collection.dart';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/staff_application.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/services/apply_bottom_sheet.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_back_button.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// Sport detail + apply — same two-stage flow as [ClubDetailScreen]
/// (preview → emailed detail form). No separate “application only” page.
class SportApplicationScreen extends StatefulWidget {
  final CampusSport sport;
  final CampusRepository repository;
  final Color? accentColor;

  const SportApplicationScreen({
    super.key,
    required this.sport,
    required this.repository,
    this.accentColor,
  });

  @override
  State<SportApplicationScreen> createState() => _SportApplicationScreenState();
}

class _SportApplicationScreenState extends State<SportApplicationScreen> {
  ParticipationApplication? _application;
  bool _loading = true;

  Color get _accent =>
      widget.accentColor ?? categoryAccent(widget.sport.facility);

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final mine = await widget.repository.getMyApplications();
    if (!mounted) return;
    setState(() {
      _application = mine
          .where((a) =>
              a.targetType == 'sport' && a.targetId == widget.sport.id)
          .firstOrNull;
      _loading = false;
    });
  }

  Future<void> _apply() async {
    await showApplyBottomSheet(
      context,
      repository: widget.repository,
      targetType: 'sport',
      targetId: widget.sport.id,
      targetLabel: widget.sport.name,
    );
    if (mounted) await _load();
  }

  @override
  Widget build(BuildContext context) {
    final sport = widget.sport;
    final app = _application;
    final s = AppLocale.of(context);
    final canOpenApply = app == null ||
        app.canResubmitPreview ||
        app.awaitingStudentDetail ||
        app.isOpen;
    final applyLabel = app == null
        ? s.t('common_join')
        : app.awaitingStudentDetail
            ? s.t('club_open_email_form')
            : app.isOpen
                ? s.t('club_view_application')
                : app.canResubmitPreview
                    ? s.t('club_reapply')
                    : s.t('common_join');

    return Scaffold(
      appBar: AppBar(
          title: Text(sport.name), leading: const CampusBackButton()),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
              children: [
                Row(children: [
                  CircleAvatar(
                    radius: 26,
                    backgroundColor: _accent.withValues(alpha: .14),
                    child: Icon(Icons.sports_rounded, color: _accent, size: 28),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(sport.facility,
                            style: TextStyle(
                                color: _accent,
                                fontWeight: FontWeight.w800,
                                fontSize: 13)),
                        if (sport.contact != null) ...[
                          const SizedBox(height: 2),
                          Text(sport.contact!,
                              style: const TextStyle(
                                  color: ArucadColors.muted, fontSize: 12.5)),
                        ],
                      ],
                    ),
                  ),
                ]),
                const SizedBox(height: 20),
                Text(s.t('common_about'),
                    style: const TextStyle(
                        fontWeight: FontWeight.w900, fontSize: 15)),
                const SizedBox(height: 8),
                const Text(
                  'Bu spora katılmak için ön başvuru doldurulur; ilgili spor '
                  'sorumlusu inceler. Onay sonrası detay formu e-posta ile gelir.',
                  style: TextStyle(fontSize: 15, height: 1.4),
                ),
                const SizedBox(height: 28),
                if (app != null) ...[
                  Card(
                    color: ArucadColors.mist,
                    elevation: 0,
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(16)),
                    child: ListTile(
                      leading: Icon(Icons.assignment_turned_in_outlined,
                          color: _accent),
                      title: Text(s.t('club_application_status')),
                      subtitle: Text(app.needsRevision &&
                              (app.reviewNote ?? '').isNotEmpty
                          ? '${app.statusLabel}: ${app.reviewNote}'
                          : app.statusLabel),
                      isThreeLine: app.needsRevision &&
                          (app.reviewNote ?? '').isNotEmpty,
                    ),
                  ),
                  const SizedBox(height: 10),
                ],
                if (app?.isApproved != true)
                  SizedBox(
                    width: double.infinity,
                    child: FilledButton(
                      onPressed: canOpenApply ? _apply : null,
                      style: FilledButton.styleFrom(
                        backgroundColor: _accent,
                        foregroundColor: onAccent(_accent),
                      ),
                      child: Text(applyLabel),
                    ),
                  )
                else
                  SizedBox(
                    width: double.infinity,
                    child: OutlinedButton.icon(
                      onPressed: null,
                      icon: Icon(Icons.check, color: _accent),
                      label: Text(app!.statusLabel),
                    ),
                  ),
              ],
            ),
    );
  }
}
