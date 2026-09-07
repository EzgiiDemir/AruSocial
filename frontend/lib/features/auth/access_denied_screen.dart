import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';

/// Shown when a signed-in account reaches an admin/trainer route their
/// role cannot open. Client-side only — the API still rejects the writes.
class AccessDeniedScreen extends StatelessWidget {
  final String title;
  final String message;
  final VoidCallback onLogout;

  const AccessDeniedScreen({
    super.key,
    required this.title,
    required this.message,
    required this.onLogout,
  });

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final s = AppLocale.of(context);
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(Icons.lock_outline, size: 48, color: scheme.onSurface),
                  const SizedBox(height: 16),
                  Text(title,
                      textAlign: TextAlign.center,
                      style: TextStyle(
                          fontSize: 20,
                          fontWeight: FontWeight.w800,
                          color: scheme.onSurface)),
                  const SizedBox(height: 8),
                  Text(message,
                      textAlign: TextAlign.center,
                      style: TextStyle(color: scheme.onSurfaceVariant)),
                  const SizedBox(height: 24),
                  FilledButton(
                    onPressed: onLogout,
                    child: Text(s.t('common_logout')),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
