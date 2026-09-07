part of '../admin_panel_screen.dart';

class _AdminLoadError extends StatelessWidget {
  final Object error;
  const _AdminLoadError({required this.error});

  @override
  Widget build(BuildContext context) {
    final e = error;
    final denied = e is ApiClientException && e.statusCode == 403;
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(denied ? Icons.lock_outline : Icons.error_outline,
                size: 36, color: ArucadColors.muted),
            const SizedBox(height: 12),
            Text(
              denied ? 'Bu bölüm için yetkin yok.' : 'Bu bölüm yüklenemedi.',
              textAlign: TextAlign.center,
              style: const TextStyle(fontWeight: FontWeight.w700),
            ),
            const SizedBox(height: 6),
            Text(
              denied
                  ? 'Erişim gerekiyorsa bir yöneticiden rolünü güncellemesini iste.'
                  : e is ApiClientException
                      ? e.message
                      : '$e',
              textAlign: TextAlign.center,
              style: const TextStyle(color: ArucadColors.muted, fontSize: 13),
            ),
          ],
        ),
      ),
    );
  }
}
