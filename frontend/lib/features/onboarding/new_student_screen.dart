import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/config/onboarding_config.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/services/service_detail_screen.dart';

/// The "First 30 Days" checklist — there's no real enrollment-date field in
/// this prototype to auto-detect a genuinely new student, so it's a
/// standing entry point (Profile + a Home card during the actual first 30
/// days) any student can open, rather than a one-time popup faking that
/// detection. Every step carries a real explanation and, where a real
/// in-app destination exists, a real "Detay" action — not just a checkbox.
class NewStudentScreen extends StatefulWidget {
  final CampusRepository repository;
  const NewStudentScreen({super.key, required this.repository});

  @override
  State<NewStudentScreen> createState() => _NewStudentScreenState();
}

class _NewStudentScreenState extends State<NewStudentScreen> {
  Set<String> _done = {};
  List<OnboardingStep> _steps = const [];
  bool _loading = true;
  bool _celebrated = false;

  @override
  void initState() {
    super.initState();
    Future.wait([
      widget.repository.getOnboardingProgress(),
      widget.repository.getOnboardingSteps(),
    ]).then((results) {
      if (!mounted) return;
      final progress = results[0]
          as ({Set<String> done, DateTime startedAt, bool eligible});
      final steps = results[1] as List<OnboardingStep>;
      setState(() {
        _done = progress.done;
        _steps = steps;
        _celebrated = steps.isNotEmpty && progress.done.length == steps.length;
        _loading = false;
      });
    });
  }

  Future<void> _toggle(String id, bool value) async {
    setState(() {
      if (value) {
        _done.add(id);
      } else {
        _done.remove(id);
      }
    });
    await widget.repository.setOnboardingStepDone(id, value);
    if (value && _done.length == _steps.length && !_celebrated) {
      _celebrated = true;
      WidgetsBinding.instance.addPostFrameCallback((_) => _showCelebration());
    }
  }

  // Real fix: this used to leave the checklist open showing "12/12
  // tamamlandı" indefinitely once finished — a page with nothing left to
  // do shouldn't linger on screen. It's still reachable from Profile any
  // time (see the class doc comment), it just doesn't sit open once
  // there's nothing left to check off.
  Future<void> _showCelebration() async {
    await showDialog<void>(
      context: context,
      builder: (ctx) => Dialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
        child: Padding(
          padding: const EdgeInsets.all(28),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            const Text('🎉', style: TextStyle(fontSize: 48)),
            const SizedBox(height: 12),
            const Text('Tebrikler!',
                style: TextStyle(fontWeight: FontWeight.w900, fontSize: 22)),
            const SizedBox(height: 8),
            const Text(
                'ARUCAD\'a gerçekten hoş geldin — ilk 30 gün rehberini tamamladın. '
                'Artık kampüsü kendi başına keşfetmeye hazırsın.',
                textAlign: TextAlign.center,
                style: TextStyle(color: ArucadColors.muted)),
            const SizedBox(height: 20),
            SizedBox(
              width: double.infinity,
              child: FilledButton(
                onPressed: () => Navigator.of(ctx).pop(),
                child: const Text('Teşekkürler!'),
              ),
            ),
          ]),
        ),
      ),
    );
    if (mounted) Navigator.of(context).pop();
  }

  Future<void> _openDetail(OnboardingStep step) async {
    switch (step.actionKind) {
      case OnboardingActionKind.service:
        final services = await widget.repository.getServices();
        final service = services.where((s) => s.id == step.refId).firstOrNull;
        if (!mounted) return;
        if (service == null) {
          _showInfoSheet(step);
          return;
        }
        await Navigator.of(context).push(MaterialPageRoute(
            builder: (_) => ServiceDetailScreen(
                service: service, repository: widget.repository)));
        return;
      case OnboardingActionKind.list:
        if (step.refId == 'clubs') {
          final clubs = await widget.repository.getClubs();
          if (!mounted) return;
          _showListSheet(step, clubs.map((c) => '${c.name} · ${c.category}').toList());
        } else {
          final sports = await widget.repository.getSports();
          if (!mounted) return;
          _showListSheet(step, sports.map((s) => '${s.name} · ${s.facility}').toList());
        }
        return;
      case OnboardingActionKind.info:
        _showInfoSheet(step);
    }
  }

  void _showInfoSheet(OnboardingStep step) {
    showModalBottomSheet<void>(
      context: context,
      builder: (ctx) => Padding(
        padding: const EdgeInsets.all(24),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(step.title, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 17)),
          const SizedBox(height: 10),
          Text(step.detail, style: const TextStyle(height: 1.4)),
        ]),
      ),
    );
  }

  void _showListSheet(OnboardingStep step, List<String> items) {
    showModalBottomSheet<void>(
      context: context,
      builder: (ctx) => Padding(
        padding: const EdgeInsets.all(24),
        child: Column(mainAxisSize: MainAxisSize.min, crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(step.title, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 17)),
          const SizedBox(height: 6),
          Text(step.detail, style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5)),
          const SizedBox(height: 14),
          ConstrainedBox(
            constraints: const BoxConstraints(maxHeight: 320),
            child: ListView.separated(
              shrinkWrap: true,
              itemCount: items.length,
              separatorBuilder: (_, __) => const Divider(height: 1),
              itemBuilder: (_, i) => Padding(
                padding: const EdgeInsets.symmetric(vertical: 10),
                child: Text(items[i]),
              ),
            ),
          ),
        ]),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final groups = <String, List<OnboardingStep>>{};
    for (final step in _steps) {
      groups.putIfAbsent(step.group, () => []).add(step);
    }
    final progress = _steps.isEmpty ? 0.0 : _done.length / _steps.length;

    return Scaffold(
      appBar: AppBar(title: const Text('Kampüse Hoş Geldin')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.fromLTRB(20, 16, 20, 28),
              children: [
                Card(
                  color: ArucadColors.primary,
                  child: Padding(
                    padding: const EdgeInsets.all(20),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text('İlk 30 Gün',
                            style: TextStyle(
                                color: Colors.white70, fontWeight: FontWeight.w800)),
                        const SizedBox(height: 8),
                        Text('${_done.length} / ${_steps.length} tamamlandı',
                            style: const TextStyle(
                                color: Colors.white,
                                fontSize: 24,
                                fontWeight: FontWeight.w900)),
                        const SizedBox(height: 10),
                        LinearProgressIndicator(
                            value: progress,
                            backgroundColor: Colors.white24,
                            color: Colors.white),
                      ],
                    ),
                  ),
                ),
                const SizedBox(height: 18),
                for (final entry in groups.entries) ...[
                  Padding(
                    padding: const EdgeInsets.only(bottom: 8, top: 6),
                    child: Text(entry.key,
                        style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 14)),
                  ),
                  Card(
                    child: Column(
                      children: [
                        for (var i = 0; i < entry.value.length; i++) ...[
                          if (i > 0) const Divider(height: 1),
                          Padding(
                            padding: const EdgeInsets.symmetric(horizontal: 8),
                            child: CheckboxListTile(
                              value: _done.contains(entry.value[i].id),
                              onChanged: (v) => _toggle(entry.value[i].id, v ?? false),
                              controlAffinity: ListTileControlAffinity.leading,
                              title: Text(entry.value[i].title),
                              subtitle: Padding(
                                padding: const EdgeInsets.only(top: 4, right: 4),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(entry.value[i].detail,
                                        style: const TextStyle(
                                            color: ArucadColors.muted, fontSize: 12.5, height: 1.35)),
                                    const SizedBox(height: 6),
                                    Align(
                                      alignment: Alignment.centerLeft,
                                      child: TextButton.icon(
                                        onPressed: () => _openDetail(entry.value[i]),
                                        icon: const Icon(Icons.explore_outlined, size: 16),
                                        label: const Text('Detay / Keşfet'),
                                        style: TextButton.styleFrom(
                                            padding: const EdgeInsets.symmetric(horizontal: 4),
                                            minimumSize: Size.zero,
                                            tapTargetSize: MaterialTapTargetSize.shrinkWrap),
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ),
                          ),
                        ],
                      ],
                    ),
                  ),
                ],
              ],
            ),
    );
  }
}

extension _FirstOrNull<T> on Iterable<T> {
  T? get firstOrNull => isEmpty ? null : first;
}
