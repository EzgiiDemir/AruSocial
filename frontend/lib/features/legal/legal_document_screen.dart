import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_back_button.dart';

class LegalDocumentScreen extends StatelessWidget {
  const LegalDocumentScreen({
    super.key,
    required this.title,
    required this.assetPath,
    this.publicUrl,
  });

  final String title;
  final String assetPath;
  final String? publicUrl;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text(title), leading: const CampusBackButton()),
      body: FutureBuilder<String>(
        future: rootBundle.loadString(assetPath),
        builder: (context, snap) {
          if (!snap.hasData) {
            return const Center(child: CircularProgressIndicator());
          }
          return ListView(
            padding: const EdgeInsets.fromLTRB(20, 12, 20, 32),
            children: [
              if (publicUrl != null && publicUrl!.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Text(
                    'Herkese açık adres: $publicUrl',
                    style: const TextStyle(color: ArucadColors.muted, fontSize: 12),
                  ),
                ),
              Text(snap.data!, style: const TextStyle(height: 1.45, fontSize: 14)),
            ],
          );
        },
      ),
    );
  }
}
