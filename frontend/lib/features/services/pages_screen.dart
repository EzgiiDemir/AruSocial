import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/admin_page.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/widgets/block_renderer.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_back_button.dart';

/// Student-facing list of published Pages (Admin → Sayfalar) — the
/// "Hakkımızda / SSS / Gizlilik Politikası" style informational pages an
/// admin authors with the block editor, with nothing invented here: if no
/// page has been published yet, this is honestly empty.
class PagesListScreen extends StatefulWidget {
  final CampusRepository repository;
  const PagesListScreen({super.key, required this.repository});

  @override
  State<PagesListScreen> createState() => _PagesListScreenState();
}

class _PagesListScreenState extends State<PagesListScreen> {
  late Future<List<AdminPage>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.repository
        .getPages()
        .then((pages) => pages.where((p) => p.status == AdminPageStatus.published).toList());
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Sayfalar'), leading: const CampusBackButton()),
      body: FutureBuilder<List<AdminPage>>(
        future: _future,
        builder: (context, snap) {
          if (!snap.hasData) return const Center(child: CircularProgressIndicator());
          final pages = snap.data!;
          if (pages.isEmpty) {
            return const Center(
              child: Padding(
                padding: EdgeInsets.all(24),
                child: Text('Henüz yayınlanmış bir sayfa yok.',
                    style: TextStyle(color: ArucadColors.muted)),
              ),
            );
          }
          return ListView.separated(
            padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
            itemCount: pages.length,
            separatorBuilder: (_, __) => const SizedBox(height: 8),
            itemBuilder: (context, i) {
              final p = pages[i];
              return Card(
                child: ListTile(
                  leading: const Icon(Icons.article_outlined, color: ArucadColors.primary),
                  title: Text(p.title, style: const TextStyle(fontWeight: FontWeight.w800)),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.of(context).push(
                      MaterialPageRoute(builder: (_) => _PageDetailScreen(page: p))),
                ),
              );
            },
          );
        },
      ),
    );
  }
}

class _PageDetailScreen extends StatelessWidget {
  final AdminPage page;
  const _PageDetailScreen({required this.page});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(page.title), leading: const CampusBackButton()),
        body: ListView(
          padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
          children: [
            if (page.blocks.isEmpty)
              const Text('Bu sayfanın içeriği henüz girilmedi.',
                  style: TextStyle(color: ArucadColors.muted))
            else
              BlockRenderer(blocks: page.blocks),
          ],
        ),
      );
}
