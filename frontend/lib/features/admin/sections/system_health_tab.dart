part of '../admin_panel_screen.dart';

// ------------------------------------------------------------ System Health

class _SystemHealthTab extends StatefulWidget {
  final CampusRepository repository;
  const _SystemHealthTab({required this.repository});
  @override
  State<_SystemHealthTab> createState() => _SystemHealthTabState();
}

class _SystemHealthTabState extends State<_SystemHealthTab> {
  late Future<SystemHealth> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getSystemHealth();
  }

  void _reload() => setState(() { _future = widget.repository.getSystemHealth(); });

  @override
  Widget build(BuildContext context) {
    return FutureBuilder<SystemHealth>(
      future: _future,
      builder: (context, snap) {
        if (snap.hasError) return _AdminLoadError(error: snap.error!);
        if (!snap.hasData) return const Center(child: CircularProgressIndicator());
        final health = snap.data!;
        return RefreshIndicator(
          onRefresh: () async => _reload(),
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              Row(children: [
                const Expanded(
                    child: Text('Sistem Sağlığı',
                        style: TextStyle(fontSize: 20, fontWeight: FontWeight.w800))),
                IconButton(icon: const Icon(Icons.refresh), onPressed: _reload),
              ]),
              const SizedBox(height: 4),
              const Text('Gerçek zamanlı yapılandırma/erişilebilirlik durumu — canlı bir izleme paneli değil.',
                  style: TextStyle(color: ArucadColors.muted, fontSize: 12.5)),
              const SizedBox(height: 16),
              _HealthRow(label: 'Veritabanı bağlantısı', ok: health.database),
              _HealthRow(label: 'Rota sağlayıcı (OSRM)', ok: health.routingConfigured,
                  hint: health.routingConfigured ? null : 'backend/.env — ROUTING_BASE_URL boş'),
              _HealthRow(label: 'Görsel moderasyon (Groq/OpenAI anahtarı)', ok: health.moderationConfigured,
                  hint: health.moderationConfigured ? null : 'Entegrasyonlar sekmesinden ekle'),
              _HealthRow(label: 'Ask ARUCAD (Groq anahtarı)', ok: health.aiConfigured,
                  hint: health.aiConfigured ? null : 'backend/.env — GROQ_API_KEY boş'),
              _HealthRow(label: 'Microsoft Entra girişi', ok: health.entraConfigured,
                  hint: health.entraConfigured ? null : 'Entegrasyonlar sekmesinden ekle'),
              _HealthRow(label: 'WordPress entegrasyonu', ok: health.wordpressConfigured,
                  hint: health.wordpressConfigured ? null : 'Entegrasyonlar sekmesinden ekle'),
              _HealthRow(label: 'Gerçek zamanlı bildirim (Reverb)', ok: health.broadcastingConfigured,
                  hint: health.broadcastingConfigured ? null : 'backend/.env — REVERB_APP_KEY boş'),
            ],
          ),
        );
      },
    );
  }
}

class _HealthRow extends StatelessWidget {
  final String label;
  final bool ok;
  final String? hint;
  const _HealthRow({required this.label, required this.ok, this.hint});

  @override
  Widget build(BuildContext context) => Card(
        child: ListTile(
          leading: Icon(ok ? Icons.check_circle : Icons.error_outline,
              color: ok ? ArucadColors.success : ArucadColors.warning),
          title: Text(label, style: const TextStyle(fontWeight: FontWeight.w700)),
          subtitle: Text(ok ? 'Bağlı / yapılandırıldı' : (hint ?? 'Yapılandırılmadı'),
              style: TextStyle(color: ok ? ArucadColors.success : ArucadColors.muted, fontSize: 12.5)),
        ),
      );
}
