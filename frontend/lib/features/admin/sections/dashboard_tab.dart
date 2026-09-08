part of '../admin_panel_screen.dart';

/// Single admin analytics experience — all totals come from
/// [CampusRepository.getAdminStats] (DB aggregates in REST). No local
/// summing of full event/club lists for KPI cards.
class _DashboardTab extends StatefulWidget {
  final CampusRepository repository;
  final UserRole role;
  final ValueChanged<_AdminSection> onNavigate;
  const _DashboardTab(
      {required this.repository, required this.role, required this.onNavigate});

  @override
  State<_DashboardTab> createState() => _DashboardTabState();
}

class _DashboardTabState extends State<_DashboardTab> {
  late Future<AdminStats> _future;
  int _days = 14;
  Timer? _poll;

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getAdminStats(days: _days);
    _poll = Timer.periodic(const Duration(seconds: 45), (_) {
      if (mounted) _reload();
    });
  }

  @override
  void dispose() {
    _poll?.cancel();
    super.dispose();
  }

  void _reload([int? days]) {
    setState(() {
      if (days != null) _days = days;
      _future = widget.repository.getAdminStats(days: _days);
    });
  }

  @override
  Widget build(BuildContext context) {
    final strings = AdminLocale.of(context);
    final wide = MediaQuery.of(context).size.width >= 700;
    return FutureBuilder<AdminStats>(
      future: _future,
      builder: (context, snap) {
        if (snap.hasError) return _AdminLoadError(error: snap.error!);
        if (!snap.hasData) {
          return const Center(child: CircularProgressIndicator());
        }
        final s = snap.data!;
        return RefreshIndicator(
          onRefresh: () async => _reload(),
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              // ---- Top KPI
              Text(strings.t('admin_dash_overview'),
                  style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
              const SizedBox(height: 10),
              GridView.count(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                crossAxisCount: wide ? 4 : 2,
                crossAxisSpacing: 10,
                mainAxisSpacing: 10,
                childAspectRatio: 1.35,
                children: [
                  _StatCard(
                      label: 'Kullanıcılar',
                      value: '${s.userSummary.realAccountCount}',
                      sub: '${s.userSummary.activeAccounts} aktif',
                      icon: Icons.people_outline,
                      onTap: () => widget.onNavigate(_AdminSection.users)),
                  _StatCard(
                      label: 'Bekleyen başvurular',
                      value: '${s.applications.pending}',
                      icon: Icons.assignment_turned_in_outlined,
                      highlight: s.applications.pending > 0,
                      onTap: () => widget.onNavigate(_AdminSection.applications)),
                  _StatCard(
                      label: 'Bekleyen aktiviteler',
                      value: '${s.events.pendingReview}',
                      icon: Icons.pending_actions_outlined,
                      highlight: s.events.pendingReview > 0,
                      onTap: () =>
                          widget.onNavigate(_AdminSection.pendingActivities)),
                  _StatCard(
                      label: 'Check-in',
                      value: '${s.checkins.total}',
                      icon: Icons.pin_drop_outlined,
                      onTap: null),
                  _StatCard(
                      label: 'Etkinlikler',
                      value: '${s.events.total}',
                      icon: Icons.event_outlined,
                      onTap: () => widget.onNavigate(_AdminSection.events)),
                  _StatCard(
                      label: 'Moderasyon',
                      value: '${s.social.moderationReportsUnresolved}',
                      icon: Icons.flag_outlined,
                      highlight: s.social.moderationReportsUnresolved > 0,
                      onTap: () => widget.onNavigate(_AdminSection.moderation)),
                  _StatCard(
                      label: 'Başarısız e-posta',
                      value: '${s.email.failed}',
                      icon: Icons.error_outline,
                      highlight: s.email.failed > 0,
                      onTap: () => widget.onNavigate(_AdminSection.emailLog)),
                  _StatCard(
                      label: 'Bildirimler',
                      value: '${s.notifications.unread}',
                      sub: '${s.notifications.total} toplam',
                      icon: Icons.notifications_outlined,
                      onTap: null),
                ],
              ),

              // ---- Operations
              const SizedBox(height: 26),
              const Text('Operasyonlar',
                  style: TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
              const SizedBox(height: 10),
              Wrap(spacing: 10, runSpacing: 10, children: [
                _QuickAction(
                    icon: Icons.assignment_outlined,
                    label: 'Başvurular (${s.applications.pending})',
                    onTap: () => widget.onNavigate(_AdminSection.applications)),
                _QuickAction(
                    icon: Icons.pending_actions_outlined,
                    label: 'Aktiviteler (${s.events.pendingReview})',
                    onTap: () =>
                        widget.onNavigate(_AdminSection.pendingActivities)),
                if (widget.role.canModerate)
                  _QuickAction(
                      icon: Icons.flag_outlined,
                      label:
                          'Moderasyon (${s.social.moderationReportsUnresolved})',
                      onTap: () =>
                          widget.onNavigate(_AdminSection.moderation)),
                _QuickAction(
                    icon: Icons.mail_outline,
                    label: 'E-posta hataları (${s.email.failed})',
                    onTap: () => widget.onNavigate(_AdminSection.emailLog)),
              ]),

              // ---- Analytics filters
              const SizedBox(height: 26),
              Row(children: [
                const Expanded(
                  child: Text('Analitik',
                      style: TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
                ),
                for (final d in [7, 14, 30])
                  Padding(
                    padding: const EdgeInsets.only(left: 6),
                    child: SelectableChip(
                      label: '${d}g',
                      selected: _days == d,
                      onSelected: (_) => _reload(d),
                    ),
                  ),
              ]),
              const SizedBox(height: 8),
              Text(s.appUsage.note,
                  style: const TextStyle(color: ArucadColors.muted, fontSize: 11.5)),
              const SizedBox(height: 14),
              Text(strings.t('admin_stats_most_checked_in_places'),
                  style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13.5)),
              const SizedBox(height: 8),
              _RankedBarList(
                items: [
                  for (final p in s.checkins.mostCheckedInPlaces)
                    (p.placeName, p.total)
                ],
                emptyLabel: strings.t('admin_stats_no_data_yet'),
              ),
              const SizedBox(height: 14),
              Text(strings.t('admin_stats_checkins_by_day'),
                  style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13.5)),
              const SizedBox(height: 8),
              _DailyTrendChart(data: s.checkins.byDay),

              const SizedBox(height: 20),
              Text(strings.t('admin_stats_most_joined_events'),
                  style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13.5)),
              const SizedBox(height: 8),
              _RankedBarList(
                items: [
                  for (final e in s.events.mostJoinedEvents) (e.title, e.total)
                ],
                emptyLabel: strings.t('admin_stats_no_data_yet'),
              ),

              const SizedBox(height: 20),
              const Text('Başvurular (tür)',
                  style: TextStyle(fontWeight: FontWeight.w800, fontSize: 13.5)),
              const SizedBox(height: 8),
              _RankedBarList(
                items: [
                  for (final k in s.applications.byType) (k.kind, k.total)
                ],
                emptyLabel: strings.t('admin_stats_no_data_yet'),
              ),

              const SizedBox(height: 20),
              Text(strings.t('admin_stats_social'),
                  style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13.5)),
              const SizedBox(height: 10),
              GridView.count(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                crossAxisCount: wide ? 4 : 2,
                crossAxisSpacing: 10,
                mainAxisSpacing: 10,
                childAspectRatio: 1.6,
                children: [
                  _StatCard(
                      label: 'Posts',
                      value: '${s.social.feedPosts}',
                      icon: Icons.dynamic_feed_outlined,
                      onTap: null),
                  _StatCard(
                      label: 'Comments',
                      value: '${s.social.comments}',
                      icon: Icons.chat_bubble_outline,
                      onTap: null),
                  _StatCard(
                      label: 'Likes',
                      value: '${s.social.likes}',
                      icon: Icons.favorite_outline,
                      onTap: null),
                  _StatCard(
                      label: 'Reviews',
                      value: '${s.social.reviews}',
                      sub: s.social.reviews > 0
                          ? '⭐ ${s.social.averageRating.toStringAsFixed(1)}'
                          : null,
                      icon: Icons.star_outline,
                      onTap: null),
                ],
              ),

              const SizedBox(height: 20),
              Text(strings.t('admin_stats_catalog'),
                  style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13.5)),
              const SizedBox(height: 10),
              GridView.count(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                crossAxisCount: wide ? 4 : 2,
                crossAxisSpacing: 10,
                mainAxisSpacing: 10,
                childAspectRatio: 1.6,
                children: [
                  _StatCard(
                      label: 'Clubs',
                      value: '${s.catalog.clubs}',
                      icon: Icons.groups_outlined,
                      onTap: () => widget.onNavigate(_AdminSection.clubs)),
                  _StatCard(
                      label: 'Sports',
                      value: '${s.catalog.sports}',
                      icon: Icons.sports_outlined,
                      onTap: () => widget.onNavigate(_AdminSection.sports)),
                  _StatCard(
                      label: 'Services',
                      value: '${s.catalog.services}',
                      icon: Icons.support_agent_outlined,
                      onTap: () => widget.onNavigate(_AdminSection.services)),
                  _StatCard(
                      label: 'Food',
                      value: '${s.catalog.foodVenues}',
                      icon: Icons.restaurant_outlined,
                      onTap: () => widget.onNavigate(_AdminSection.food)),
                  _StatCard(
                      label: 'Media',
                      value: '${s.catalog.mediaItems}',
                      icon: Icons.photo_library_outlined,
                      onTap: () => widget.onNavigate(_AdminSection.media)),
                  _StatCard(
                      label: 'Surveys',
                      value: '${s.surveys.total}',
                      icon: Icons.poll_outlined,
                      onTap: () => widget.onNavigate(_AdminSection.surveys)),
                ],
              ),
            ],
          ),
        );
      },
    );
  }
}

/// Kept as a thin alias so deep-links / leftover nav still resolve to the
/// unified dashboard experience (no second data source).
class _StatsTab extends StatelessWidget {
  final CampusRepository repository;
  final UserRole role;
  final ValueChanged<_AdminSection> onNavigate;
  const _StatsTab({
    required this.repository,
    required this.role,
    required this.onNavigate,
  });

  @override
  Widget build(BuildContext context) => _DashboardTab(
        repository: repository,
        role: role,
        onNavigate: onNavigate,
      );
}

class _RankedBarList extends StatelessWidget {
  final List<(String, int)> items;
  final String emptyLabel;
  const _RankedBarList({required this.items, required this.emptyLabel});

  @override
  Widget build(BuildContext context) {
    if (items.isEmpty) {
      return Card(
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Text(emptyLabel,
              style: const TextStyle(color: ArucadColors.muted)),
        ),
      );
    }
    final maxCount = items.map((e) => e.$2).fold(0, (m, v) => v > m ? v : m);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            for (final item in items)
              Padding(
                padding: const EdgeInsets.only(bottom: 12),
                child: Builder(builder: (context) {
                  final accent = categoryAccent(item.$1);
                  return Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Row(children: [
                          Container(
                            width: 8,
                            height: 8,
                            margin: const EdgeInsets.only(right: 8),
                            decoration: BoxDecoration(
                                color: accent, shape: BoxShape.circle),
                          ),
                          Expanded(
                              child: Text(item.$1,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  style: const TextStyle(
                                      fontWeight: FontWeight.w700, fontSize: 13))),
                          Text('${item.$2}',
                              style: TextStyle(
                                  fontWeight: FontWeight.w800,
                                  fontSize: 13,
                                  color: accent)),
                        ]),
                        const SizedBox(height: 5),
                        ClipRRect(
                          borderRadius: BorderRadius.circular(999),
                          child: LinearProgressIndicator(
                            value: maxCount == 0 ? 0 : item.$2 / maxCount,
                            minHeight: 5,
                            backgroundColor: accent.withValues(alpha: .1),
                            color: accent,
                          ),
                        ),
                      ]);
                }),
              ),
          ],
        ),
      ),
    );
  }
}

class _DailyTrendChart extends StatelessWidget {
  final List<DailyCount> data;
  const _DailyTrendChart({required this.data});

  @override
  Widget build(BuildContext context) {
    if (data.isEmpty) return const SizedBox.shrink();
    final maxCount =
        data.map((e) => e.total).fold(0, (m, v) => v > m ? v : m);
    return Card(
      child: Padding(
        padding: const EdgeInsets.fromLTRB(12, 16, 12, 10),
        child: SizedBox(
          height: 90,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              for (final d in data)
                Expanded(
                  child: Padding(
                    padding: const EdgeInsets.symmetric(horizontal: 2),
                    child: Tooltip(
                      message: '${d.day}: ${d.total}',
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.end,
                        children: [
                          Stack(alignment: Alignment.bottomCenter, children: [
                            Container(
                              height: 70,
                              decoration: BoxDecoration(
                                color: ArucadColors.mist,
                                borderRadius: BorderRadius.circular(3),
                              ),
                            ),
                            Container(
                              height: maxCount == 0
                                  ? 2
                                  : 70 * (d.total / maxCount).clamp(0.03, 1.0),
                              decoration: const BoxDecoration(
                                color: ArucadColors.blue,
                                borderRadius: BorderRadius.vertical(
                                    top: Radius.circular(3), bottom: Radius.circular(3)),
                              ),
                            ),
                          ]),
                          const SizedBox(height: 4),
                          Text(d.day.length >= 5 ? d.day.substring(5) : d.day,
                              style: const TextStyle(
                                  fontSize: 8, color: ArucadColors.muted)),
                        ],
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _QuickAction extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback? onTap;
  const _QuickAction(
      {required this.icon, required this.label, required this.onTap});

  @override
  Widget build(BuildContext context) => OutlinedButton.icon(
        onPressed: onTap,
        icon: Icon(icon, size: 18),
        label: Text(label),
      );
}

class _StatCard extends StatelessWidget {
  final String label;
  final String value;
  final String? sub;
  final IconData icon;
  final bool highlight;
  final VoidCallback? onTap;

  const _StatCard({
    required this.label,
    required this.value,
    this.sub,
    required this.icon,
    this.highlight = false,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    // Filament-style dashboard: one calm accent, not a hash-cycled rainbow
    // per card. `highlight` stays the only colored state, for a genuine
    // pending/unresolved/failed signal.
    return Card(
      color: highlight
          ? ArucadColors.warning.withValues(alpha: .1)
          : ArucadColors.primary.withValues(alpha: .06),
      child: InkWell(
        borderRadius: BorderRadius.circular(14),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.fromLTRB(12, 10, 12, 10),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.center,
            mainAxisSize: MainAxisSize.max,
            children: [
              Icon(icon,
                  color: highlight ? ArucadColors.warning : ArucadColors.primary,
                  size: 18),
              const SizedBox(height: 6),
              FittedBox(
                fit: BoxFit.scaleDown,
                alignment: Alignment.centerLeft,
                child: Text(value,
                    style: const TextStyle(
                        fontWeight: FontWeight.w900, fontSize: 20, height: 1.1)),
              ),
              Text(label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                      color: ArucadColors.muted, fontSize: 11, height: 1.15)),
              if (sub != null)
                Text(sub!,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                        color: ArucadColors.warning, fontSize: 10, height: 1.1)),
            ],
          ),
        ),
      ),
    );
  }
}
