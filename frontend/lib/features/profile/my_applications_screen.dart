import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/staff_application.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
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
                            final a = _rows[index];
                            return Card(
                              child: ListTile(
                                title: Text(a.targetLabel ?? '${a.targetType} · ${a.targetId}',
                                    style: const TextStyle(fontWeight: FontWeight.w800)),
                                subtitle: Text([
                                  a.statusLabel,
                                  if (a.responsibleStaffName != null) a.responsibleStaffName!,
                                  if (a.reviewNote != null && a.reviewNote!.isNotEmpty)
                                    a.reviewNote!,
                                ].join('\n')),
                                isThreeLine: true,
                                trailing: a.detailFormUrl != null &&
                                        a.detailFormUrl!.isNotEmpty &&
                                        a.awaitingStudentDetail
                                    ? TextButton(
                                        onPressed: () =>
                                            launchUrl(Uri.parse(a.detailFormUrl!)),
                                        child: Text(s.t('apps_form')),
                                      )
                                    : null,
                              ),
                            );
                          },
                        ),
                ),
    );
  }
}
