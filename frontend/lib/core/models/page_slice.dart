/// One length-aware page from a list endpoint.
///
/// Matches `meta.pagination` on GET /feed, /notifications, /admin/audit-log
/// and /admin/email-logs. Missing/malformed pagination is treated as a
/// single page of whatever arrived in `data`.
class PageSlice<T> {
  static const int defaultPerPage = 20;

  final List<T> items;
  final int currentPage;
  final int perPage;
  final int total;
  final int lastPage;

  const PageSlice({
    required this.items,
    required this.currentPage,
    required this.perPage,
    required this.total,
    required this.lastPage,
  });

  bool get hasMore => currentPage < lastPage;

  factory PageSlice.fromEnvelope(
    Map<String, dynamic> envelope,
    T Function(Map<String, dynamic>) parse,
  ) {
    final raw = envelope['data'];
    final items = <T>[];
    if (raw is List) {
      for (final item in raw) {
        if (item is Map<String, dynamic>) items.add(parse(item));
      }
    }
    final meta = envelope['meta'];
    final pagination = meta is Map<String, dynamic> ? meta['pagination'] : null;
    if (pagination is! Map<String, dynamic>) {
      return PageSlice(
        items: items,
        currentPage: 1,
        perPage: items.isEmpty ? defaultPerPage : items.length,
        total: items.length,
        lastPage: 1,
      );
    }
    int asInt(String key, int fallback) {
      final value = pagination[key];
      if (value is int) return value;
      if (value is num) return value.toInt();
      return fallback;
    }

    final currentPage = asInt('currentPage', 1);
    final lastPage = asInt('lastPage', 1);
    return PageSlice(
      items: items,
      currentPage: currentPage < 1 ? 1 : currentPage,
      perPage: asInt('perPage', items.isEmpty ? defaultPerPage : items.length),
      total: asInt('total', items.length),
      lastPage: lastPage < 1 ? 1 : lastPage,
    );
  }
}
