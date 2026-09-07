import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/services/sport_application_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

class ExploreSportsScreen extends StatefulWidget {
  final CampusRepository repository;

  const ExploreSportsScreen({super.key, required this.repository});

  @override
  State<ExploreSportsScreen> createState() => _ExploreSportsScreenState();
}

class _ExploreSportsScreenState extends State<ExploreSportsScreen> {
  bool _loading = true;
  List<CampusSport> _sports = const [];
  String _query = '';
  String? _facility; // null = all
  int _visible = kPageSize;
  final _searchController = TextEditingController();
  final _searchFocus = FocusNode();

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _searchController.dispose();
    _searchFocus.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    try {
      final sports = await widget.repository.getSports();
      if (!mounted) return;
      setState(() {
        _sports = sports.isNotEmpty ? sports : campusSports;
        _loading = false;
        _visible = kPageSize;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _sports = campusSports;
        _loading = false;
        _visible = kPageSize;
      });
    }
  }

  void _resetVisible() => _visible = kPageSize;

  List<String> get _facilities {
    final found = <String>{};
    for (final s in _sports) {
      final f = s.facility.trim();
      if (f.isNotEmpty) found.add(f);
    }
    final list = found.toList()..sort();
    return list;
  }

  List<CampusSport> get _filtered {
    final q = _query.trim().toLowerCase();
    return _sports.where((s) {
      if (_facility != null && s.facility != _facility) return false;
      if (q.isEmpty) return true;
      return s.name.toLowerCase().contains(q) ||
          s.facility.toLowerCase().contains(q);
    }).toList();
  }

  Future<void> _openFilters() async {
    final strings = AppLocale.of(context);
    final selected = await showModalBottomSheet<String?>(
      context: context,
      showDragHandle: true,
      shape: const RoundedRectangleBorder(
          borderRadius: BorderRadius.vertical(top: Radius.circular(20))),
      builder: (ctx) {
        return SafeArea(
          child: ListView(
            shrinkWrap: true,
            children: [
              Padding(
                padding: const EdgeInsets.fromLTRB(20, 4, 20, 8),
                child: Text(strings.t('explore_sports_filter'),
                    style: const TextStyle(
                        fontWeight: FontWeight.w900, fontSize: 16)),
              ),
              ListTile(
                title: Text(strings.t('category_all'),
                    style: TextStyle(
                        fontWeight: _facility == null
                            ? FontWeight.w800
                            : FontWeight.w500)),
                trailing: _facility == null
                    ? const Icon(Icons.check, color: ArucadColors.primary)
                    : null,
                onTap: () => Navigator.pop(ctx, ''),
              ),
              for (final facility in _facilities)
                ListTile(
                  title: Text(facility,
                      style: TextStyle(
                          fontWeight: _facility == facility
                              ? FontWeight.w800
                              : FontWeight.w500)),
                  trailing: _facility == facility
                      ? const Icon(Icons.check, color: ArucadColors.primary)
                      : null,
                  onTap: () => Navigator.pop(ctx, facility),
                ),
              const SizedBox(height: 8),
            ],
          ),
        );
      },
    );
    if (!mounted || selected == null) return;
    setState(() {
      _facility = selected.isEmpty ? null : selected;
      _resetVisible();
    });
  }

  void _openSport(CampusSport sport, Color accent) {
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => SportApplicationScreen(
            sport: sport,
            repository: widget.repository,
            accentColor: accent,
          ),
    ));
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final filtered = _filtered;
    final shown = _visible.clamp(0, filtered.length);
    final visible = filtered.take(shown).toList();

    return Scaffold(
      appBar: AppBar(title: Text(strings.t('discover_sports'))),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: CustomScrollView(
                slivers: [
                  SliverToBoxAdapter(
                    child: Padding(
                      padding: const EdgeInsets.fromLTRB(20, 12, 12, 8),
                      child: Row(
                        children: [
                          Expanded(
                            child: TextField(
                              controller: _searchController,
                              focusNode: _searchFocus,
                              textInputAction: TextInputAction.search,
                              onChanged: (v) => setState(() {
                                _query = v;
                                _resetVisible();
                              }),
                              decoration: InputDecoration(
                                hintText: strings.t('explore_sports_search'),
                                prefixIcon: const Icon(Icons.search_rounded),
                                filled: true,
                                fillColor: ArucadColors.mist,
                                contentPadding: const EdgeInsets.symmetric(
                                    horizontal: 14, vertical: 12),
                                border: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(14),
                                  borderSide: BorderSide.none,
                                ),
                                enabledBorder: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(14),
                                  borderSide: BorderSide.none,
                                ),
                                focusedBorder: OutlineInputBorder(
                                  borderRadius: BorderRadius.circular(14),
                                  borderSide: const BorderSide(
                                      color: ArucadColors.primary, width: 1.2),
                                ),
                              ),
                            ),
                          ),
                          const SizedBox(width: 4),
                          IconButton(
                            tooltip: strings.t('explore_sports_filter'),
                            onPressed: _openFilters,
                            icon: Badge(
                              isLabelVisible: _facility != null,
                              smallSize: 8,
                              backgroundColor: ArucadColors.primary,
                              child: Icon(
                                Icons.filter_list_rounded,
                                color: _facility != null
                                    ? ArucadColors.primary
                                    : ArucadColors.ink,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ),
                  if (_facility != null)
                    SliverToBoxAdapter(
                      child: Padding(
                        padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
                        child: Align(
                          alignment: Alignment.centerLeft,
                          child: InputChip(
                            label: Text(_facility!),
                            deleteIcon: const Icon(Icons.close, size: 16),
                            onDeleted: () => setState(() {
                              _facility = null;
                              _resetVisible();
                            }),
                            onPressed: _openFilters,
                          ),
                        ),
                      ),
                    ),
                  if (filtered.isEmpty)
                    SliverFillRemaining(
                      hasScrollBody: false,
                      child: Center(
                        child: Text(strings.t('explore_sports_empty'),
                            style: const TextStyle(color: ArucadColors.muted)),
                      ),
                    )
                  else
                    SliverPadding(
                      padding: const EdgeInsets.fromLTRB(20, 4, 20, 28),
                      sliver: SliverList.builder(
                        itemCount: visible.length + 1,
                        itemBuilder: (context, i) {
                          if (i == visible.length) {
                            return LoadMoreButton(
                              shown: shown,
                              total: filtered.length,
                              itemLabel: 'spor',
                              onTap: () =>
                                  setState(() => _visible += kPageSize),
                            );
                          }
                          final sport = visible[i];
                          final accent = brandAccentAt(i);
                          return Padding(
                            padding: EdgeInsets.only(top: i == 0 ? 0 : 10),
                            child: _SportCard(
                              sport: sport,
                              accent: accent,
                              icon: _sportIcon(sport),
                              joinLabel: strings.t('explore_sports_join'),
                              onOpen: () => _openSport(sport, accent),
                            ),
                          );
                        },
                      ),
                    ),
                ],
              ),
            ),
    );
  }
}

IconData _sportIcon(CampusSport sport) {
  final n = '${sport.id} ${sport.name}'.toLowerCase();
  if (n.contains('basket')) return Icons.sports_basketball_rounded;
  if (n.contains('tennis') || n.contains('tenis')) {
    return Icons.sports_tennis_rounded;
  }
  if (n.contains('futsal') || n.contains('futbol') || n.contains('football')) {
    return Icons.sports_soccer_rounded;
  }
  if (n.contains('bowl')) return Icons.sports_rounded;
  if (n.contains('dart')) return Icons.gps_fixed_rounded;
  if (n.contains('billard') || n.contains('bilardo')) {
    return Icons.sports_bar_rounded;
  }
  return Icons.sports_rounded;
}

class _SportCard extends StatelessWidget {
  final CampusSport sport;
  final Color accent;
  final IconData icon;
  final String joinLabel;
  final VoidCallback onOpen;

  const _SportCard({
    required this.sport,
    required this.accent,
    required this.icon,
    required this.joinLabel,
    required this.onOpen,
  });

  @override
  Widget build(BuildContext context) {
    return Card(
      color: ArucadColors.paper,
      elevation: 0,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onOpen,
        hoverColor: Colors.transparent,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              Container(
                width: 52,
                height: 52,
                decoration: BoxDecoration(
                  color: accent.withValues(alpha: .12),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Icon(icon, color: accent, size: 28),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(sport.name,
                        style: const TextStyle(
                            fontWeight: FontWeight.w900, fontSize: 15)),
                    const SizedBox(height: 4),
                    Text(sport.facility,
                        style: const TextStyle(
                            color: ArucadColors.muted, fontSize: 13)),
                  ],
                ),
              ),
              OutlinedButton(
                onPressed: onOpen,
                style: OutlinedButton.styleFrom(
                  foregroundColor: accent,
                  side: BorderSide(color: accent.withValues(alpha: .45)),
                  backgroundColor: Colors.transparent,
                  padding:
                      const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                  shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(12)),
                ),
                child: Text(joinLabel,
                    style: const TextStyle(fontWeight: FontWeight.w800)),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
