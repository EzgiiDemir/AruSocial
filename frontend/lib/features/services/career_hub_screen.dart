import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/services/service_detail_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// Career, promoted to a first-class hub instead of one Service Detail
/// entry — ARUCAD's real Career & Alumni Office covers all of these, but
/// there's no separately-modeled data per sub-topic yet, so each card
/// honestly resolves to the same real office (contact / Service Detail),
/// with the sub-topic named in the email subject line rather than pretending
/// there's a dedicated inbox for each.
class CareerHubScreen extends StatefulWidget {
  final CampusRepository repository;
  const CareerHubScreen({super.key, required this.repository});

  @override
  State<CareerHubScreen> createState() => _CareerHubScreenState();
}

class _CareerHubScreenState extends State<CareerHubScreen> {
  static const _topics = [
    (Icons.work_outline, 'Internships / Staj'),
    (Icons.business_center_outlined, 'Jobs / İş İlanları'),
    (Icons.event_outlined, 'Career Events'),
    (Icons.description_outlined, 'CV Review'),
    (Icons.record_voice_over_outlined, 'Mock Interview'),
    (Icons.folder_open_outlined, 'Portfolio Review'),
    (Icons.groups_outlined, 'Alumni Network'),
  ];

  CampusService? _service;

  @override
  void initState() {
    super.initState();
    widget.repository.getServices().then((services) {
      if (!mounted) return;
      setState(() {
        _service = services.where((s) => s.id == 'career').firstOrNull;
      });
    });
  }

  Future<void> _contact(String topic) async {
    final service = _service;
    if (service == null) return;
    await launchUrl(Uri(
      scheme: 'mailto',
      path: service.contact,
      query: 'subject=${Uri.encodeComponent(topic)}',
    ));
  }

  @override
  Widget build(BuildContext context) {
    final service = _service;
    return Scaffold(
      appBar: AppBar(title: const Text('Career')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
        children: [
          const Text('ARUCAD Kariyer ve Mezun Ofisi',
              style: TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
          const SizedBox(height: 6),
          Text(
              service?.description ??
                  'İş/staj fırsatları, kariyer etkinlikleri, portfolyo değerlendirmesi ve mezun ağı bağlantıları.',
              style: const TextStyle(color: ArucadColors.muted, fontSize: 13)),
          const SizedBox(height: 20),
          GridView.count(
            crossAxisCount: 2,
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            crossAxisSpacing: 10,
            mainAxisSpacing: 10,
            childAspectRatio: 1.5,
            children: [
              for (final (icon, label) in _topics)
                Builder(builder: (context) {
                  final accent = categoryAccent(label);
                  return InkWell(
                    onTap: () => _contact(label),
                    borderRadius: BorderRadius.circular(16),
                    child: Container(
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(
                          color: accent.withValues(alpha: .12),
                          borderRadius: BorderRadius.circular(16)),
                      child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(icon, color: accent),
                            const SizedBox(height: 8),
                            Text(label,
                                style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13, color: accent)),
                          ]),
                    ),
                  );
                }),
            ],
          ),
          const SizedBox(height: 20),
          if (service != null)
            OutlinedButton.icon(
              onPressed: () => Navigator.of(context).push(MaterialPageRoute(
                  builder: (_) => ServiceDetailScreen(service: service))),
              icon: const Icon(Icons.info_outline),
              label: const Text('Hizmet Detayları'),
            ),
        ],
      ),
    );
  }
}

extension _FirstOrNull<T> on Iterable<T> {
  T? get firstOrNull => isEmpty ? null : first;
}
