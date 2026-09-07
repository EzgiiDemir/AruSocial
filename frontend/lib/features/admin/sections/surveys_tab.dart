part of '../admin_panel_screen.dart';

// --------------------------------------------------------------- Surveys

/// Real poll/survey management — the same shape the student-facing popup
/// (`SurveyPopup`) reads from `/surveys/active`. Options are replaced
/// wholesale on every save (see `SurveyController::upsert()`'s own doc
/// comment) — simplest correct model for something that shouldn't be
/// restructured mid-vote anyway.
class _SurveysTab extends StatefulWidget {
  final CampusRepository repository;
  final String adminName;
  const _SurveysTab({required this.repository, required this.adminName});
  @override
  State<_SurveysTab> createState() => _SurveysTabState();
}

class _SurveysTabState extends State<_SurveysTab> {
  late Future<List<Survey>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getAllSurveys();
  }

  void _reload() => setState(() { _future = widget.repository.getAllSurveys(); });

  Future<void> _editSurvey([Survey? existing]) async {
    final questionC = TextEditingController(text: existing?.question);
    final descC = TextEditingController(text: existing?.description);
    final optionControllers = [
      for (final o in existing?.options ?? const <SurveyOption>[]) TextEditingController(text: o.label),
    ];
    if (optionControllers.length < 2) {
      optionControllers.addAll(List.generate(2 - optionControllers.length, (_) => TextEditingController()));
    }
    var multipleChoice = existing?.multipleChoice ?? false;
    var anonymous = existing?.anonymous ?? true;
    var showResults = existing?.showResults ?? true;
    var active = existing?.active ?? true;

    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: Text(existing == null ? 'Yeni Anket' : 'Anketi Düzenle'),
          content: SizedBox(
            width: 380,
            child: _DialogShell(fields: [
              TextField(controller: questionC, decoration: const InputDecoration(labelText: 'Soru')),
              TextField(
                  controller: descC,
                  decoration: const InputDecoration(labelText: 'Açıklama (opsiyonel)'),
                  maxLines: 2),
              const _DialogSection('Seçenekler'),
              for (var i = 0; i < optionControllers.length; i++)
                Row(children: [
                  Expanded(
                    child: TextField(
                        controller: optionControllers[i],
                        decoration: InputDecoration(labelText: 'Seçenek ${i + 1}')),
                  ),
                  if (optionControllers.length > 2)
                    IconButton(
                      icon: const Icon(Icons.close, size: 18),
                      onPressed: () => setDialogState(() => optionControllers.removeAt(i)),
                    ),
                ]),
              Align(
                alignment: Alignment.centerLeft,
                child: TextButton.icon(
                  onPressed: () => setDialogState(() => optionControllers.add(TextEditingController())),
                  icon: const Icon(Icons.add, size: 16),
                  label: const Text('Seçenek ekle'),
                ),
              ),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Çoklu seçim'),
                value: multipleChoice,
                onChanged: (v) => setDialogState(() => multipleChoice = v),
              ),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Anonim'),
                value: anonymous,
                onChanged: (v) => setDialogState(() => anonymous = v),
              ),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Sonuçları göster'),
                value: showResults,
                onChanged: (v) => setDialogState(() => showResults = v),
              ),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Aktif'),
                value: active,
                onChanged: (v) => setDialogState(() => active = v),
              ),
            ]),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Vazgeç')),
            FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Kaydet')),
          ],
        ),
      ),
    );
    final options = optionControllers.map((c) => c.text.trim()).where((t) => t.isNotEmpty).toList();
    if (saved != true || questionC.text.trim().isEmpty || options.length < 2) return;
    await widget.repository.upsertSurvey(
      id: existing?.id,
      question: questionC.text.trim(),
      description: descC.text.trim().isEmpty ? null : descC.text.trim(),
      multipleChoice: multipleChoice,
      anonymous: anonymous,
      showResults: showResults,
      active: active,
      options: options,
    );
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName,
        action: existing == null ? 'create' : 'update',
        targetType: 'survey',
        targetLabel: questionC.text.trim());
    _reload();
  }

  Future<void> _delete(Survey s) async {
    await widget.repository.deleteSurvey(s.id);
    await AuditLogStore.logIfMock(widget.repository,
        actorName: widget.adminName, action: 'delete', targetType: 'survey', targetLabel: s.question);
    _reload();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      floatingActionButton: FloatingActionButton(
          onPressed: () => _editSurvey(), child: const Icon(Icons.add)),
      body: FutureBuilder<List<Survey>>(
        future: _future,
        builder: (context, snap) {
          if (snap.hasError) return _AdminLoadError(error: snap.error!);
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
          final surveys = snap.data!;
          if (surveys.isEmpty) {
            return const Center(
              child: Padding(
                padding: EdgeInsets.all(32),
                child: Text('Henüz anket yok.', style: TextStyle(color: ArucadColors.muted)),
              ),
            );
          }
          return ListView.separated(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 90),
            itemCount: surveys.length,
            separatorBuilder: (_, __) => const SizedBox(height: 8),
            itemBuilder: (context, i) {
              final s = surveys[i];
              return Card(
                child: ListTile(
                  title: Text(s.question,
                      maxLines: 1, overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontWeight: FontWeight.w800)),
                  subtitle: Text(
                      '${s.options.length} seçenek · ${s.totalVotes} oy · '
                      '${s.active ? "Aktif" : "Pasif"}',
                      maxLines: 1, overflow: TextOverflow.ellipsis),
                  onTap: () => _editSurvey(s),
                  trailing: IconButton(
                    icon: const Icon(Icons.delete_outline),
                    onPressed: () => _delete(s),
                  ),
                ),
              );
            },
          );
        },
      ),
    );
  }
}
