import 'dart:async';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/admin_strings.dart';
import 'package:arucad_campus_prototype/core/models/achievement_career.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/staff_application.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/chat_realtime_service.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// Trainer-facing career, consultation and appointment queues. Writes go
/// through the same `/admin/*` campus-ops APIs the Admin panel uses;
/// backend `permission:career.manage` / `appointments.manage` already
/// includes the trainer role.
class TrainerCampusOpsTab extends StatefulWidget {
  final CampusRepository repository;
  const TrainerCampusOpsTab({super.key, required this.repository});

  @override
  State<TrainerCampusOpsTab> createState() => _TrainerCampusOpsTabState();
}

class _TrainerCampusOpsTabState extends State<TrainerCampusOpsTab> {
  late Future<List<CareerApplication>> _careerApps;
  late Future<List<ConsultationApplication>> _consultApps;
  late Future<List<AppointmentBooking>> _appointments;
  Timer? _poll;
  ChatRealtimeService? _realtime;
  StreamSubscription<void>? _rtSub;

  @override
  void initState() {
    super.initState();
    _reload();
    _poll = Timer.periodic(const Duration(seconds: 15), (_) {
      if (mounted) _reload();
    });
    unawaited(_bindRealtime());
  }

  Future<void> _bindRealtime() async {
    try {
      final me = await widget.repository.getMe();
      if (!mounted) return;
      final rt = ChatRealtimeService.forRepository(widget.repository);
      _realtime = rt;
      _rtSub = rt.appointmentChanged.listen((_) {
        if (mounted) _reload();
      });
      await rt.start(
        userId: me.id,
        userName: me.name,
        subscribeOpsAppointments: me.parsedRole.canManageAppointments,
      );
    } catch (_) {}
  }

  @override
  void dispose() {
    _poll?.cancel();
    unawaited(_rtSub?.cancel() ?? Future.value());
    unawaited(_realtime?.dispose() ?? Future.value());
    super.dispose();
  }

  void _reload() => setState(() {
        _careerApps = widget.repository.getAdminCareerApplications();
        _consultApps = widget.repository.getAdminConsultationApplications();
        _appointments = widget.repository.getAdminAppointments();
      });

  Future<void> _setCareerStatus(CareerApplication a, String status) async {
    try {
      await widget.repository.updateCareerApplication(a.id, status: status);
      if (mounted) _reload();
    } on ApiClientException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  Future<void> _setConsultStatus(ConsultationApplication a, String status) async {
    try {
      await widget.repository.updateConsultationApplication(a.id, status: status);
      if (mounted) _reload();
    } on ApiClientException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  Future<void> _setAppointment(AppointmentBooking a, String status) async {
    try {
      await widget.repository.updateAdminAppointment(a.id, status: status);
      if (mounted) _reload();
    } on ApiClientException catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
    }
  }

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    return DefaultTabController(
      length: 3,
      child: Column(children: [
        TabBar(
          labelColor: ArucadColors.navy,
          tabs: [
            Tab(text: strings.t('trainer_tab_career')),
            Tab(text: strings.t('trainer_tab_consult')),
            Tab(text: strings.t('trainer_tab_appointments')),
          ],
        ),
        Expanded(
          child: TabBarView(children: [
            _AppsList<CareerApplication>(
              future: _careerApps,
              empty: strings.t('trainer_empty_career'),
              titleOf: (a) => a.opportunityTitle ?? a.opportunityId,
              subtitleOf: (a) => '${a.userName ?? a.userId} · ${a.status}',
              onStatus: _setCareerStatus,
              statuses: const ['pending', 'reviewed', 'shortlisted', 'rejected', 'accepted'],
            ),
            _AppsList<ConsultationApplication>(
              future: _consultApps,
              empty: strings.t('trainer_empty_consult'),
              titleOf: (a) => a.consultationTitle ?? a.consultationId,
              subtitleOf: (a) => '${a.userName ?? a.userId} · ${a.status}',
              onStatus: _setConsultStatus,
              statuses: const ['pending', 'reviewed', 'accepted', 'rejected'],
            ),
            FutureBuilder<List<AppointmentBooking>>(
              future: _appointments,
              builder: (context, snap) {
                if (snap.hasError) {
                  return Center(child: Text('${strings.t('trainer_load_failed')}: ${snap.error}'));
                }
                if (!snap.hasData) {
                  return const Center(child: CircularProgressIndicator());
                }
                final items = snap.data!;
                if (items.isEmpty) {
                  return Center(
                      child: Text(strings.t('trainer_empty_appt'),
                          style: const TextStyle(color: ArucadColors.muted)));
                }
                return ListView(
                  padding: const EdgeInsets.all(16),
                  children: [
                    for (final a in items)
                      Card(
                        child: ListTile(
                          title: Text(a.studentName ?? strings.t('trainer_student'),
                              style: const TextStyle(fontWeight: FontWeight.w700)),
                          subtitle: Text([
                            a.staffName ?? a.staffProfileId,
                            '${a.date} ${a.startTime}',
                            a.status,
                            if (a.subject != null) a.subject!,
                          ].join(' · ')),
                          trailing: PopupMenuButton<String>(
                            onSelected: (v) => _setAppointment(a, v),
                            itemBuilder: (_) => [
                              PopupMenuItem(value: 'approved', child: Text(strings.t('trainer_approve'))),
                              PopupMenuItem(value: 'rejected', child: Text(strings.t('trainer_reject'))),
                              PopupMenuItem(value: 'cancelled', child: Text(strings.t('trainer_cancel'))),
                              PopupMenuItem(value: 'completed', child: Text(strings.t('trainer_complete'))),
                            ],
                          ),
                        ),
                      ),
                  ],
                );
              },
            ),
          ]),
        ),
      ]),
    );
  }
}

class _AppsList<T> extends StatelessWidget {
  final Future<List<T>> future;
  final String empty;
  final String Function(T) titleOf;
  final String Function(T) subtitleOf;
  final Future<void> Function(T, String) onStatus;
  final List<String> statuses;

  const _AppsList({
    required this.future,
    required this.empty,
    required this.titleOf,
    required this.subtitleOf,
    required this.onStatus,
    required this.statuses,
  });

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<List<T>>(
      future: future,
      builder: (context, snap) {
        if (snap.hasError) {
          return Center(
              child: Text('${AdminLocale.of(context).t('trainer_load_failed')}: ${snap.error}'));
        }
        if (!snap.hasData) return const Center(child: CircularProgressIndicator());
        final items = snap.data!;
        if (items.isEmpty) {
          return Center(child: Text(empty, style: const TextStyle(color: ArucadColors.muted)));
        }
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            for (final a in items)
              Card(
                child: ListTile(
                  title: Text(titleOf(a),
                      style: const TextStyle(fontWeight: FontWeight.w700)),
                  subtitle: Text(subtitleOf(a)),
                  trailing: PopupMenuButton<String>(
                    onSelected: (v) => onStatus(a, v),
                    itemBuilder: (_) => [
                      for (final s in statuses)
                        PopupMenuItem(value: s, child: Text(s)),
                    ],
                  ),
                ),
              ),
          ],
        );
      },
    );
  }
}
