/// In-app / store deep links. Custom scheme: `aruverse://place/{id}`.
class AppDeepLink {
  const AppDeepLink({required this.kind, this.id});

  final String kind;
  final String? id;

  static AppDeepLink? tryParse(String? raw) {
    if (raw == null || raw.isEmpty || raw == '/') return null;
    var value = raw.trim();
    if (value.startsWith('aruverse://')) {
      value = value.substring('aruverse://'.length);
    } else if (value.startsWith('/')) {
      value = value.substring(1);
    }
    final parts = value.split('/').where((p) => p.isNotEmpty).toList();
    if (parts.isEmpty) return null;
    final kind = parts.first.toLowerCase();
    const known = {'place', 'event', 'chat', 'feed', 'notifications'};
    if (!known.contains(kind)) return null;
    return AppDeepLink(
      kind: kind,
      id: parts.length > 1 ? Uri.decodeComponent(parts[1]) : null,
    );
  }
}
