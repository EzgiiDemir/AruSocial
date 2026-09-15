part of '../admin_panel_screen.dart';

// ------------------------------------------------- Moderator case review
//
// The queue a human actually works.
//
// The case and appeal endpoints have existed and been verified end to end
// for some time, and nothing in the app could open one. That gap is not
// cosmetic: held content is the mitigation for every category no
// classifier covers — gore, weapons, hate symbols, and roughly half of
// harmful text — and a hold nobody can read is indistinguishable from a
// silent delete. This section is what makes "held for review" true.

/// Severity bands, lowest number first. Priority comes from the worst
/// claim made about the content, nudged by how many separate people made
/// one, so it is a queue order rather than a verdict.
({Color color, String label}) _casePriority(int priority) {
  if (priority <= 20) return (color: ArucadColors.red, label: 'Acil');
  if (priority <= 50) return (color: ArucadColors.yellow, label: 'Yüksek');
  return (color: ArucadColors.muted, label: 'Normal');
}

String _caseAge(DateTime? at) {
  if (at == null) return '—';
  final hours = DateTime.now().difference(at).inHours;
  if (hours < 1) return 'az önce';
  if (hours < 24) return '$hours saat';
  return '${(hours / 24).floor()} gün';
}

class _ModerationCasesSection extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;

  const _ModerationCasesSection({
    required this.repository,
    required this.adminName,
  });

  @override
  State<_ModerationCasesSection> createState() =>
      _ModerationCasesSectionState();
}

class _ModerationCasesSectionState extends State<_ModerationCasesSection> {
  late Future<List<ModerationCase>> _casesFuture;
  late Future<List<ModerationAppealReview>> _appealsFuture;

  @override
  void initState() {
    super.initState();
    _reload();
  }

  void _reload() => setState(() {
        _casesFuture = widget.repository.getModerationCases();
        _appealsFuture = widget.repository.getModerationAppeals();
      });

  Future<void> _openCase(ModerationCase summary) async {
    final decided = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) => _CaseReviewSheet(
        repository: widget.repository,
        caseId: summary.id,
      ),
    );
    if (decided == true) _reload();
  }

  Future<void> _decideAppeal(
      ModerationAppealReview appeal, String outcome) async {
    try {
      await widget.repository
          .decideModerationAppeal(appeal.id, outcome: outcome);
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('İtiraz kaydedilemedi: $error')));
      return;
    }
    if (!mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(SnackBar(
        content: Text(outcome == 'overturn'
            ? 'İtiraz kabul edildi, içerik geri alındı.'
            : 'İtiraz reddedildi, karar korundu.')));
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        const Text('İnceleme kuyruğu',
            style: TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
        const SizedBox(height: 4),
        const Text(
          'Sınıflandırıcının emin olamadığı ya da bildirilen içerikler. '
          'En ağır iddia ve en çok bildirim en üstte.',
          style: TextStyle(color: ArucadColors.muted, fontSize: 12),
        ),
        const SizedBox(height: 10),
        FutureBuilder<List<ModerationCase>>(
          future: _casesFuture,
          builder: (context, snap) {
            if (snap.hasError) return _AdminLoadError(error: snap.error!);
            if (!snap.hasData) {
              return const Padding(
                padding: EdgeInsets.all(24),
                child: Center(child: CircularProgressIndicator()),
              );
            }
            final cases = snap.data!;
            if (cases.isEmpty) {
              return const Padding(
                padding: EdgeInsets.symmetric(vertical: 12),
                child: Text('Bekleyen inceleme yok.',
                    style: TextStyle(color: ArucadColors.muted)),
              );
            }
            return Column(
              children: [
                for (final c in cases) _CaseRow(item: c, onOpen: () => _openCase(c)),
              ],
            );
          },
        ),
        const SizedBox(height: 24),
        const Text('İtirazlar',
            style: TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
        const SizedBox(height: 4),
        const Text(
          'Bir öğrenci kararın yeniden değerlendirilmesini istedi. '
          'İtiraz hakkı olmayan bir moderasyon sistemi yalnızca bir filtredir.',
          style: TextStyle(color: ArucadColors.muted, fontSize: 12),
        ),
        const SizedBox(height: 10),
        FutureBuilder<List<ModerationAppealReview>>(
          future: _appealsFuture,
          builder: (context, snap) {
            if (snap.hasError) return _AdminLoadError(error: snap.error!);
            if (!snap.hasData) {
              return const Padding(
                padding: EdgeInsets.all(24),
                child: Center(child: CircularProgressIndicator()),
              );
            }
            final appeals = snap.data!;
            if (appeals.isEmpty) {
              return const Padding(
                padding: EdgeInsets.symmetric(vertical: 12),
                child: Text('Bekleyen itiraz yok.',
                    style: TextStyle(color: ArucadColors.muted)),
              );
            }
            return Column(
              children: [
                for (final a in appeals)
                  _AppealRow(
                    appeal: a,
                    onUphold: () => _decideAppeal(a, 'uphold'),
                    onOverturn: () => _decideAppeal(a, 'overturn'),
                  ),
              ],
            );
          },
        ),
      ],
    );
  }
}

class _CaseRow extends StatelessWidget {
  final ModerationCase item;
  final VoidCallback onOpen;

  const _CaseRow({required this.item, required this.onOpen});

  @override
  Widget build(BuildContext context) {
    final band = _casePriority(item.priority);
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: ListTile(
        onTap: onOpen,
        // A severity stripe rather than a tinted card: the row has to be
        // scannable in a list of thirty without reading any of them.
        leading: Container(
          width: 4,
          height: 44,
          decoration: BoxDecoration(
            color: band.color,
            borderRadius: BorderRadius.circular(2),
          ),
        ),
        title: Text('${item.contentType} · ${item.contentId}',
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
        subtitle: Text(
          '${band.label} · ${item.reportCount} bildirim · '
          '${item.source == 'user_report' ? 'kullanıcı bildirimi' : 'otomatik'} · '
          '${_caseAge(item.createdAt)}',
          style: const TextStyle(fontSize: 11, color: ArucadColors.muted),
        ),
        trailing: const Icon(Icons.chevron_right),
      ),
    );
  }
}

class _AppealRow extends StatelessWidget {
  final ModerationAppealReview appeal;
  final VoidCallback onUphold;
  final VoidCallback onOverturn;

  const _AppealRow({
    required this.appeal,
    required this.onUphold,
    required this.onOverturn,
  });

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: const EdgeInsets.only(bottom: 8),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Karar: ${appeal.originalDecision} · ${_caseAge(appeal.submittedAt)}',
                style: const TextStyle(
                    fontSize: 11, color: ArucadColors.muted)),
            const SizedBox(height: 6),
            Text(appeal.reason, style: const TextStyle(fontSize: 13)),
            const SizedBox(height: 10),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: [
                OutlinedButton(
                    onPressed: onUphold, child: const Text('Kararı koru')),
                FilledButton(
                    onPressed: onOverturn,
                    child: const Text('İtirazı kabul et')),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

/// One case, opened.
///
/// Shows what was reported, what the models said (with the model and
/// policy version, because a score nobody can attribute is a score nobody
/// can argue with later), and what this author has done before — a
/// decision made without history either punishes a first mistake too hard
/// or lets a pattern continue.
class _CaseReviewSheet extends StatefulWidget {
  final CampusRepository repository;
  final String caseId;

  const _CaseReviewSheet({required this.repository, required this.caseId});

  @override
  State<_CaseReviewSheet> createState() => _CaseReviewSheetState();
}

class _CaseReviewSheetState extends State<_CaseReviewSheet> {
  late Future<ModerationCaseDetail> _future;
  final _note = TextEditingController();
  String _accountAction = 'none';
  bool _saving = false;

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getModerationCase(widget.caseId);
  }

  @override
  void dispose() {
    _note.dispose();
    super.dispose();
  }

  Future<void> _decide(String contentDecision) async {
    setState(() => _saving = true);
    try {
      await widget.repository.decideModerationCase(
        widget.caseId,
        contentDecision: contentDecision,
        accountAction: _accountAction,
        note: _note.text.trim(),
      );
    } catch (error) {
      if (!mounted) return;
      setState(() => _saving = false);
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Karar kaydedilemedi: $error')));
      return;
    }
    if (!mounted) return;
    Navigator.of(context).pop(true);
  }

  @override
  Widget build(BuildContext context) {
    return DraggableScrollableSheet(
      expand: false,
      initialChildSize: .85,
      maxChildSize: .95,
      builder: (context, controller) => Material(
        color: ArucadColors.paper,
        borderRadius: const BorderRadius.vertical(top: Radius.circular(18)),
        child: FutureBuilder<ModerationCaseDetail>(
          future: _future,
          builder: (context, snap) {
            if (snap.hasError) {
              return Padding(
                padding: const EdgeInsets.all(24),
                child: _AdminLoadError(error: snap.error!),
              );
            }
            if (!snap.hasData) {
              return const Padding(
                padding: EdgeInsets.all(48),
                child: Center(child: CircularProgressIndicator()),
              );
            }
            final detail = snap.data!;
            return ListView(
              controller: controller,
              padding: const EdgeInsets.fromLTRB(20, 16, 20, 28),
              children: [
                Center(
                  child: Container(
                    width: 38,
                    height: 4,
                    decoration: BoxDecoration(
                      color: ArucadColors.border,
                      borderRadius: BorderRadius.circular(2),
                    ),
                  ),
                ),
                const SizedBox(height: 14),
                Text(
                  '${detail.summary.contentType} · ${detail.summary.contentId}',
                  style: const TextStyle(
                      fontWeight: FontWeight.w900, fontSize: 15),
                ),
                if (detail.preview != null && detail.preview!.isNotEmpty) ...[
                  const SizedBox(height: 10),
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: ArucadColors.canvas,
                      borderRadius: BorderRadius.circular(10),
                    ),
                    child: Text(detail.preview!,
                        style: const TextStyle(fontSize: 13)),
                  ),
                ],
                const SizedBox(height: 18),
                _SheetHeading('Bildirimler (${detail.reports.length})'),
                if (detail.reports.isEmpty)
                  const Text('Bildirim yok — otomatik olarak açıldı.',
                      style:
                          TextStyle(fontSize: 12, color: ArucadColors.muted)),
                for (final r in detail.reports)
                  Padding(
                    padding: const EdgeInsets.only(top: 6),
                    child: Text(
                      '• ${r.reasonCode}'
                      '${r.description.isEmpty ? '' : ' — ${r.description}'}',
                      style: const TextStyle(fontSize: 12),
                    ),
                  ),
                const SizedBox(height: 18),
                _SheetHeading('Model sinyalleri'),
                if (detail.signals.isEmpty)
                  const Text('Kayıtlı sinyal yok.',
                      style:
                          TextStyle(fontSize: 12, color: ArucadColors.muted)),
                for (final s in detail.signals) _SignalRow(signal: s),
                const SizedBox(height: 18),
                _SheetHeading('Yazarın geçmişi'),
                Text(
                  detail.authorHistory.isEmpty
                      ? 'Önceki ihlal kaydı yok.'
                      : detail.authorHistory.entries
                          .map((e) => '${e.key}: ${e.value}')
                          .join('  ·  '),
                  style: const TextStyle(fontSize: 12),
                ),
                const SizedBox(height: 20),
                _SheetHeading('Hesap işlemi'),
                const Text(
                  'İçerik kararından ayrıdır. Bir gönderiyi kaldırmak, onu '
                  'yazan kişi hakkında verilmiş bir hüküm değildir.',
                  style: TextStyle(fontSize: 11, color: ArucadColors.muted),
                ),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 8,
                  children: [
                    for (final action in const [
                      ('none', 'İşlem yok'),
                      ('warn', 'Uyarı'),
                      ('restrict', 'Kısıtla'),
                      ('suspend', 'Askıya al'),
                    ])
                      ChoiceChip(
                        label: Text(action.$2),
                        selected: _accountAction == action.$1,
                        onSelected: (_) =>
                            setState(() => _accountAction = action.$1),
                      ),
                  ],
                ),
                const SizedBox(height: 16),
                TextField(
                  controller: _note,
                  maxLines: 2,
                  decoration: const InputDecoration(
                    labelText: 'Gerekçe (denetim kaydına yazılır)',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 16),
                if (_saving)
                  const Center(child: CircularProgressIndicator())
                else
                  Wrap(
                    spacing: 8,
                    runSpacing: 8,
                    children: [
                      OutlinedButton(
                          onPressed: () => _decide('approve'),
                          child: const Text('Onayla')),
                      OutlinedButton(
                          onPressed: () => _decide('hold'),
                          child: const Text('Beklet')),
                      OutlinedButton(
                          onPressed: () => _decide('escalate'),
                          child: const Text('Üst incelemeye gönder')),
                      FilledButton(
                        style: FilledButton.styleFrom(
                            backgroundColor: ArucadColors.red),
                        onPressed: () => _decide('remove'),
                        child: const Text('Kaldır'),
                      ),
                    ],
                  ),
              ],
            );
          },
        ),
      ),
    );
  }
}

class _SheetHeading extends StatelessWidget {
  final String text;
  const _SheetHeading(this.text);

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 6),
        child: Text(text,
            style:
                const TextStyle(fontWeight: FontWeight.w800, fontSize: 13)),
      );
}

class _SignalRow extends StatelessWidget {
  final ModerationSignal signal;
  const _SignalRow({required this.signal});

  @override
  Widget build(BuildContext context) {
    final scores = signal.scores.entries.toList()
      ..sort((a, b) => b.value.compareTo(a.value));
    return Padding(
      padding: const EdgeInsets.only(top: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            '${signal.action}'
            '${signal.categories.isEmpty ? '' : ' · ${signal.categories.join(', ')}'}',
            style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
          ),
          if (scores.isNotEmpty)
            Text(
              scores
                  .take(3)
                  .map((e) => '${e.key} ${e.value.toStringAsFixed(3)}')
                  .join('   '),
              style: const TextStyle(
                  fontSize: 11,
                  color: ArucadColors.muted,
                  fontFeatures: [FontFeature.tabularFigures()]),
            ),
          // The model and policy version are shown, not hidden behind a
          // tooltip: "why was this blocked in March" has no answer if the
          // numbers that blocked it cannot be attributed.
          Text(
            [signal.model, signal.policyVersion]
                .whereType<String>()
                .where((s) => s.isNotEmpty)
                .join(' · '),
            style: const TextStyle(fontSize: 10, color: ArucadColors.muted),
          ),
        ],
      ),
    );
  }
}
