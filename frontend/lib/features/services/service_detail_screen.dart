import 'package:flutter/material.dart';
import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/config/campus_sites.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/building_directory_store.dart';
import 'package:arucad_campus_prototype/core/services/tour_launcher.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/map/in_app_navigation_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/block_renderer.dart';

/// Turns a service from a one-line "directory" entry into a real
/// Who/Where/When/How answer: what it actually helps with, when it's open,
/// where it physically is (as far as we know), and three real next steps —
/// contact, get to campus, or see the campus in 360°. No field here is
/// invented: hours/building/floor/room/contactPerson are only ever shown
/// when an admin has actually entered them.
class ServiceDetailScreen extends StatelessWidget {
  final CampusService service;
  const ServiceDetailScreen({super.key, required this.service});

  CampusSite get _mainCampus =>
      campusSites.firstWhere((s) => s.id == 'main', orElse: () => campusSites.first);

  Future<void> _contact() async {
    await launchUrl(Uri(
      scheme: 'mailto',
      path: service.contact,
      query: 'subject=${Uri.encodeComponent(service.title)}',
    ));
  }

  void _goToCampus(BuildContext context) {
    final site = _mainCampus;
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => InAppNavigationScreen(
        destinationName: site.name,
        destination: GeoPoint(site.lat, site.lng),
      ),
    ));
  }

  @override
  Widget build(BuildContext context) {
    final location = [service.building, service.floor, service.room]
        .whereType<String>()
        .join(', ');

    return Scaffold(
      appBar: AppBar(title: Text(service.title)),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
            decoration: BoxDecoration(
                color: ArucadColors.primary.withValues(alpha: .1),
                borderRadius: BorderRadius.circular(999)),
            child: Text(service.category,
                style: const TextStyle(color: ArucadColors.primary, fontWeight: FontWeight.w700)),
          ),
          const SizedBox(height: 14),
          Text(service.description, style: const TextStyle(fontSize: 15, height: 1.4)),
          if (service.body.isNotEmpty) ...[
            const SizedBox(height: 8),
            BlockRenderer(blocks: service.body),
          ],
          if (service.topics.isNotEmpty) ...[
            const SizedBox(height: 24),
            const Text('Ne için yardımcı olabiliriz?',
                style: TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
            const SizedBox(height: 10),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: service.topics
                  .map((t) => Chip(
                        label: Text(t),
                        backgroundColor: ArucadColors.mist,
                        side: BorderSide.none,
                      ))
                  .toList(),
            ),
          ],
          const SizedBox(height: 24),
          const Text('Bilgiler', style: TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
          const SizedBox(height: 10),
          _InfoRow(
            icon: Icons.schedule_outlined,
            label: 'Çalışma Saatleri',
            value: service.hours ?? 'Henüz girilmedi',
            muted: service.hours == null,
          ),
          _InfoRow(
            icon: Icons.place_outlined,
            label: 'Konum',
            value: location.isNotEmpty ? location : 'Henüz girilmedi',
            muted: location.isEmpty,
          ),
          if (service.contactPerson != null)
            _InfoRow(
              icon: Icons.person_outline,
              label: 'Yetkili',
              value: service.contactPerson!,
            ),
          _InfoRow(
            icon: Icons.mail_outline,
            label: 'E-posta',
            value: service.contact,
          ),
          FutureBuilder<List<DirectoryEntry>>(
            future: BuildingDirectoryStore.forService(service.id),
            builder: (context, snap) {
              final people = snap.data ?? const [];
              if (people.isEmpty) return const SizedBox.shrink();
              return Padding(
                padding: const EdgeInsets.only(top: 14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('İlgili Kişiler / Odalar',
                        style: TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
                    const SizedBox(height: 10),
                    for (final p in people)
                      _InfoRow(
                        icon: Icons.badge_outlined,
                        label: p.occupantName,
                        value: [
                          [p.building, p.floor, p.room].whereType<String>().join(', '),
                          if (p.occupantRole != null) p.occupantRole!,
                        ].where((s) => s.isNotEmpty).join(' · '),
                      ),
                  ],
                ),
              );
            },
          ),
          const SizedBox(height: 28),
          FilledButton.icon(
            onPressed: _contact,
            icon: const Icon(Icons.mail_outline),
            label: const Text('İletişime Geç'),
          ),
          const SizedBox(height: 10),
          OutlinedButton.icon(
            onPressed: () => _goToCampus(context),
            icon: const Icon(Icons.directions_walk),
            label: const Text('Kampüse Git'),
          ),
          const SizedBox(height: 10),
          OutlinedButton.icon(
            onPressed: () => open360Tour(context, _mainCampus.tourUrl),
            icon: const Icon(Icons.threed_rotation),
            label: const Text('360° Kampüs Turu'),
          ),
          if (location.isEmpty || service.hours == null) ...[
            const SizedBox(height: 20),
            Text(
              'Not: konum ve/veya çalışma saatleri henüz bilinmiyor — bu bilgiler girildiğinde '
              'Yönetim Paneli → Hizmetler üzerinden eklenebilir; "Kampüse Git" şimdilik bina/oda '
              'değil, ana kampüsün konumuna yönlendiriyor.',
              style: TextStyle(color: ArucadColors.muted, fontSize: 12),
            ),
          ],
        ],
      ),
    );
  }
}

class _InfoRow extends StatelessWidget {
  final IconData icon;
  final String label;
  final String value;
  final bool muted;

  const _InfoRow({
    required this.icon,
    required this.label,
    required this.value,
    this.muted = false,
  });

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, size: 18, color: ArucadColors.muted),
            const SizedBox(width: 10),
            Expanded(
              child: RichText(
                text: TextSpan(
                  style: const TextStyle(color: ArucadColors.ink, fontSize: 13.5),
                  children: [
                    TextSpan(text: '$label: ', style: const TextStyle(fontWeight: FontWeight.w700)),
                    TextSpan(
                        text: value,
                        style: TextStyle(color: muted ? ArucadColors.muted : ArucadColors.ink)),
                  ],
                ),
              ),
            ),
          ],
        ),
      );
}
