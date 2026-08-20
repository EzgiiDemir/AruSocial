import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/services/service_detail_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// "Neye ihtiyacın var?" — a single named entry point that fans out to the
/// real service that actually handles each category, instead of leaving
/// students to guess which of 10 services covers their situation. Every
/// category here maps to a real `CampusService`; "Safety" has no dedicated
/// ARUCAD office on file, so it honestly routes to Student Affairs (the
/// real general escalation point) rather than inventing a Safety desk.
class NeedHelpScreen extends StatefulWidget {
  final CampusRepository repository;
  const NeedHelpScreen({super.key, required this.repository});

  @override
  State<NeedHelpScreen> createState() => _NeedHelpScreenState();
}

class _NeedHelpCategory {
  final IconData icon;
  final String label;
  final String serviceId;
  const _NeedHelpCategory(this.icon, this.label, this.serviceId);
}

const _categories = [
  _NeedHelpCategory(Icons.school_outlined, 'Akademik', 'academic-advising'),
  _NeedHelpCategory(Icons.account_balance_outlined, 'İdari', 'student-affairs'),
  _NeedHelpCategory(Icons.favorite_border, 'Ruh Sağlığı (PDR)', 'pdr'),
  _NeedHelpCategory(Icons.public_outlined, 'Uluslararası', 'international'),
  _NeedHelpCategory(Icons.home_outlined, 'Barınma', 'dormitory'),
  _NeedHelpCategory(Icons.laptop_outlined, 'IT', 'it'),
  _NeedHelpCategory(Icons.accessible_outlined, 'Erişilebilirlik', 'accessibility'),
  _NeedHelpCategory(Icons.work_outline, 'Kariyer', 'career'),
  _NeedHelpCategory(Icons.search_outlined, 'Kayıp & Bulunan', 'lost-found'),
  _NeedHelpCategory(Icons.shield_outlined, 'Güvenlik', 'student-affairs'),
];

class _NeedHelpScreenState extends State<NeedHelpScreen> {
  List<CampusService> _services = const [];

  @override
  void initState() {
    super.initState();
    widget.repository.getServices().then((services) {
      if (mounted) setState(() => _services = services);
    });
  }

  void _open(_NeedHelpCategory category) {
    final service = _services.where((s) => s.id == category.serviceId).firstOrNull;
    if (service == null) {
      ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Bu hizmet henüz tanımlı değil.')));
      return;
    }
    Navigator.of(context)
        .push(MaterialPageRoute(builder: (_) => ServiceDetailScreen(service: service)));
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Yardım Al')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
        children: [
          const Text('Neye ihtiyacın var?',
              style: TextStyle(fontWeight: FontWeight.w900, fontSize: 18)),
          const SizedBox(height: 4),
          const Text('Bir kategori seç, seni doğru hizmete yönlendirelim.',
              style: TextStyle(color: ArucadColors.muted, fontSize: 12.5)),
          const SizedBox(height: 18),
          GridView.count(
            crossAxisCount: 2,
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            crossAxisSpacing: 10,
            mainAxisSpacing: 10,
            childAspectRatio: 1.4,
            children: [
              for (final category in _categories)
                Builder(builder: (context) {
                  final accent = categoryAccent(category.label);
                  return InkWell(
                    onTap: () => _open(category),
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
                            Icon(category.icon, color: accent),
                            const SizedBox(height: 8),
                            Text(category.label,
                                style: TextStyle(fontWeight: FontWeight.w700, fontSize: 13, color: accent)),
                          ]),
                    ),
                  );
                }),
            ],
          ),
        ],
      ),
    );
  }
}

extension _FirstOrNull<T> on Iterable<T> {
  T? get firstOrNull => isEmpty ? null : first;
}
