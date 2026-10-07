import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/place/place_detail_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// Every campus place ranked by its persistent, all-time public check-ins.
///
/// This answers a different question from "what is nearest": students open
/// Live density still uses recent activity elsewhere; this historical list
/// deliberately does not forget check-ins when time passes or users sign out.
class PopularPlacesScreen extends StatefulWidget {
  final CampusRepository repository;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;

  const PopularPlacesScreen({
    super.key,
    required this.repository,
    required this.mapProvider,
    required this.analyticsTracker,
  });

  @override
  State<PopularPlacesScreen> createState() => _PopularPlacesScreenState();
}

class _PopularPlacesScreenState extends State<PopularPlacesScreen> {
  bool _loading = true;
  List<CampusPlace> _ranked = const [];
  final _searchController = TextEditingController();
  String _query = '';
  String? _category;
  int _page = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  void _resetPage() => _page = 0;

  Future<void> _load() async {
    List<CampusPlace> places = const [];
    try {
      places = await widget.repository.getPlaces();
    } catch (_) {}

    final ranked = [...places]..sort((a, b) {
        final byCheckins = b.totalCheckins.compareTo(a.totalCheckins);
        return byCheckins != 0 ? byCheckins : a.name.compareTo(b.name);
      });

    if (!mounted) return;
    setState(() {
      _ranked = ranked;
      _loading = false;
    });
  }

  List<String> get _categories {
    final found = <String>{};
    for (final p in _ranked) {
      final c = p.category.trim();
      if (c.isNotEmpty) found.add(c);
    }

    return found.toList()..sort();
  }

  /// Search and category narrow the list, but never re-rank it: the number
  /// beside a place is its position on campus, not its position in whatever
  /// happens to be on screen. A filtered "#4" that was really #11 would be
  /// a different, and wrong, claim.
  List<(int, CampusPlace)> get _filtered {
    final q = _query.trim().toLowerCase();
    final out = <(int, CampusPlace)>[];
    for (var i = 0; i < _ranked.length; i++) {
      final place = _ranked[i];
      if (_category != null && place.category != _category) continue;
      if (q.isNotEmpty &&
          !place.name.toLowerCase().contains(q) &&
          !place.category.toLowerCase().contains(q)) {
        continue;
      }
      out.add((i + 1, place));
    }

    return out;
  }

  Future<void> _openFilters() async {
    final strings = AppLocale.of(context);
    final selected = await showModalBottomSheet<String?>(
      context: context,
      showDragHandle: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) => SafeArea(
        child: ListView(
          shrinkWrap: true,
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(20, 4, 20, 8),
              child: Text(
                strings.t('popular_places_filter'),
                style:
                    const TextStyle(fontWeight: FontWeight.w900, fontSize: 16),
              ),
            ),
            ListTile(
              title: Text(
                strings.t('category_all'),
                style: TextStyle(
                  fontWeight:
                      _category == null ? FontWeight.w800 : FontWeight.w500,
                ),
              ),
              trailing: _category == null
                  ? const Icon(Icons.check, color: ArucadColors.primary)
                  : null,
              onTap: () => Navigator.pop(ctx, ''),
            ),
            for (final category in _categories)
              ListTile(
                title: Text(
                  category,
                  style: TextStyle(
                    fontWeight: _category == category
                        ? FontWeight.w800
                        : FontWeight.w500,
                  ),
                ),
                trailing: _category == category
                    ? const Icon(Icons.check, color: ArucadColors.primary)
                    : null,
                onTap: () => Navigator.pop(ctx, category),
              ),
            const SizedBox(height: 8),
          ],
        ),
      ),
    );
    if (!mounted || selected == null) return;
    setState(() {
      _category = selected.isEmpty ? null : selected;
      _resetPage();
    });
  }

  void _open(CampusPlace place) {
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => PlaceDetailScreen(
        place: place,
        repository: widget.repository,
        mapProvider: widget.mapProvider,
        analyticsTracker: widget.analyticsTracker,
      ),
    ));
  }

  Widget _empty(String message) => ListView(children: [
        const SizedBox(height: 120),
        const Icon(Icons.local_fire_department_outlined,
            size: 44, color: ArucadColors.muted),
        const SizedBox(height: 12),
        Center(
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 40),
            child: Text(message,
                textAlign: TextAlign.center,
                style: const TextStyle(color: ArucadColors.muted)),
          ),
        ),
      ]);

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final filtered = _filtered;
    final pageCount =
        filtered.isEmpty ? 0 : (filtered.length / kPageSize).ceil();
    final safePage =
        (pageCount == 0 ? 0 : _page.clamp(0, pageCount - 1)).toInt();
    final pageStart = safePage * kPageSize;
    final pageEnd = (pageStart + kPageSize).clamp(0, filtered.length).toInt();
    final pageItems = filtered.sublist(pageStart, pageEnd);
    final busiest = _ranked.isEmpty ? 0 : _ranked.first.totalCheckins;

    return Scaffold(
      appBar: AppBar(title: Text(strings.t('popular_places_title'))),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: _ranked.isEmpty
                  ? _empty(strings.t('popular_places_empty'))
                  : CustomScrollView(
                      slivers: [
                        SliverToBoxAdapter(
                          child: Padding(
                            padding: const EdgeInsets.fromLTRB(20, 14, 20, 0),
                            child: Center(
                              child: ConstrainedBox(
                                constraints:
                                    const BoxConstraints(maxWidth: 520),
                                child: Row(
                                  children: [
                                    Expanded(
                                      child: TextField(
                                        controller: _searchController,
                                        textInputAction: TextInputAction.search,
                                        onChanged: (v) => setState(() {
                                          _query = v;
                                          _resetPage();
                                        }),
                                        decoration: InputDecoration(
                                          hintText: strings
                                              .t('popular_places_search_hint'),
                                          prefixIcon:
                                              const Icon(Icons.search_rounded),
                                          suffixIcon: _query.isEmpty
                                              ? null
                                              : IconButton(
                                                  icon: const Icon(
                                                      Icons.close_rounded),
                                                  tooltip:
                                                      strings.t('action_clear'),
                                                  onPressed: () => setState(() {
                                                    _searchController.clear();
                                                    _query = '';
                                                    _resetPage();
                                                  }),
                                                ),
                                          filled: true,
                                          fillColor: Colors.white,
                                          contentPadding:
                                              const EdgeInsets.symmetric(
                                                  horizontal: 14, vertical: 12),
                                          border: OutlineInputBorder(
                                            borderRadius:
                                                BorderRadius.circular(14),
                                            borderSide: BorderSide.none,
                                          ),
                                          enabledBorder: OutlineInputBorder(
                                            borderRadius:
                                                BorderRadius.circular(14),
                                            borderSide: BorderSide.none,
                                          ),
                                          focusedBorder: OutlineInputBorder(
                                            borderRadius:
                                                BorderRadius.circular(14),
                                            borderSide: const BorderSide(
                                                color: ArucadColors.primary,
                                                width: 1.2),
                                          ),
                                        ),
                                      ),
                                    ),
                                    const SizedBox(width: 4),
                                    IconButton(
                                      tooltip:
                                          strings.t('popular_places_filter'),
                                      onPressed: _openFilters,
                                      icon: Badge(
                                        isLabelVisible: _category != null,
                                        smallSize: 8,
                                        backgroundColor: ArucadColors.primary,
                                        child: Icon(
                                          Icons.filter_list_rounded,
                                          color: _category != null
                                              ? ArucadColors.primary
                                              : ArucadColors.ink,
                                        ),
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            ),
                          ),
                        ),
                        if (_category != null)
                          SliverToBoxAdapter(
                            child: Padding(
                              padding: const EdgeInsets.fromLTRB(20, 8, 20, 0),
                              child: Align(
                                alignment: Alignment.centerLeft,
                                child: InputChip(
                                  label: Text(_category!),
                                  deleteIcon: const Icon(Icons.close, size: 16),
                                  onDeleted: () => setState(() {
                                    _category = null;
                                    _resetPage();
                                  }),
                                  onPressed: _openFilters,
                                ),
                              ),
                            ),
                          ),
                        SliverToBoxAdapter(
                          child: Padding(
                            padding: const EdgeInsets.fromLTRB(20, 12, 20, 0),
                            child: Text(
                              strings.t('popular_places_subtitle'),
                              style: const TextStyle(
                                  color: ArucadColors.muted, fontSize: 12.5),
                            ),
                          ),
                        ),
                        if (filtered.isEmpty)
                          SliverFillRemaining(
                            hasScrollBody: false,
                            child: Center(
                              child: Text(strings.t('explore_places_empty'),
                                  style: const TextStyle(
                                      color: ArucadColors.muted)),
                            ),
                          )
                        else
                          SliverPadding(
                            padding: const EdgeInsets.fromLTRB(20, 14, 20, 28),
                            sliver: SliverList.builder(
                              itemCount: pageItems.length + 1,
                              itemBuilder: (_, i) {
                                if (i == pageItems.length) {
                                  return _PaginationBar(
                                    page: safePage,
                                    pageCount: pageCount,
                                    onChanged: (page) =>
                                        setState(() => _page = page),
                                  );
                                }
                                final (rank, place) = pageItems[i];

                                return Padding(
                                  padding:
                                      EdgeInsets.only(top: i == 0 ? 0 : 10),
                                  child: _PopularPlaceCard(
                                    rank: rank,
                                    place: place,
                                    busiest: busiest,
                                    onTap: () => _open(place),
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

class _PaginationBar extends StatelessWidget {
  final int page;
  final int pageCount;
  final ValueChanged<int> onChanged;

  const _PaginationBar({
    required this.page,
    required this.pageCount,
    required this.onChanged,
  });

  @override
  Widget build(BuildContext context) {
    if (pageCount <= 1) return const SizedBox(height: 8);
    final strings = AppLocale.of(context);

    final first =
        (page - 2).clamp(0, (pageCount - 5).clamp(0, pageCount)).toInt();
    final last = (first + 5).clamp(0, pageCount).toInt();

    return Padding(
      padding: const EdgeInsets.only(top: 18),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          _PageButton(
            icon: Icons.chevron_left_rounded,
            tooltip: strings.t('pagination_previous'),
            enabled: page > 0,
            onTap: () => onChanged(page - 1),
          ),
          for (var i = first; i < last; i++) ...[
            const SizedBox(width: 6),
            _PageButton(
              label: '${i + 1}',
              selected: i == page,
              onTap: () => onChanged(i),
            ),
          ],
          const SizedBox(width: 6),
          _PageButton(
            icon: Icons.chevron_right_rounded,
            tooltip: strings.t('pagination_next'),
            enabled: page < pageCount - 1,
            onTap: () => onChanged(page + 1),
          ),
        ],
      ),
    );
  }
}

class _PageButton extends StatelessWidget {
  final String? label;
  final IconData? icon;
  final String? tooltip;
  final bool selected;
  final bool enabled;
  final VoidCallback onTap;

  const _PageButton({
    this.label,
    this.icon,
    this.tooltip,
    this.selected = false,
    this.enabled = true,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final accent = selected ? ArucadColors.primary : ArucadColors.ink;
    final button = SizedBox.square(
      dimension: 40,
      child: Material(
        color: selected ? ArucadColors.primary : ArucadColors.paper,
        shape: CircleBorder(
          side: BorderSide(
              color: selected ? ArucadColors.primary : ArucadColors.border),
        ),
        child: InkWell(
          customBorder: const CircleBorder(),
          onTap: enabled ? onTap : null,
          child: Center(
            child: icon != null
                ? Icon(icon,
                    size: 21,
                    color: enabled
                        ? accent
                        : ArucadColors.muted.withValues(alpha: .45))
                : Text(label!,
                    style: TextStyle(
                        color: selected ? Colors.white : accent,
                        fontWeight: FontWeight.w800)),
          ),
        ),
      ),
    );
    return tooltip == null ? button : Tooltip(message: tooltip!, child: button);
  }
}

/// A ranked card: position, name, live check-in count, and a bar showing how
/// busy it is relative to the busiest place on campus.
class _PopularPlaceCard extends StatelessWidget {
  final int rank;
  final CampusPlace place;
  final int busiest;
  final VoidCallback onTap;

  const _PopularPlaceCard({
    required this.rank,
    required this.place,
    required this.busiest,
    required this.onTap,
  });

  /// The brand cycle — red, blue, yellow, green — keyed off the rank, so a
  /// place keeps its colour wherever it appears. The previous
  /// gold/silver/bronze medals were not ARUCAD colours at all.
  Color get _rankColor => brandAccentAt(rank - 1);

  @override
  Widget build(BuildContext context) {
    final accent = _rankColor;
    final share =
        busiest <= 0 ? 0.0 : (place.totalCheckins / busiest).clamp(0.0, 1.0);

    return Material(
      color: ArucadColors.paper,
      borderRadius: BorderRadius.circular(18),
      child: InkWell(
        borderRadius: BorderRadius.circular(18),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Container(
              width: 34,
              height: 34,
              alignment: Alignment.center,
              decoration: BoxDecoration(
                color: accent.withValues(alpha: .14),
                shape: BoxShape.circle,
              ),
              child: Text('$rank',
                  style: TextStyle(
                      fontWeight: FontWeight.w900,
                      fontSize: 15,
                      color: accent)),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(place.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                          fontWeight: FontWeight.w900, fontSize: 15)),
                  const SizedBox(height: 2),
                  Text(
                    place.category,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(
                        color: ArucadColors.muted, fontSize: 12),
                  ),
                  const SizedBox(height: 8),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(999),
                    child: LinearProgressIndicator(
                      value: share,
                      minHeight: 6,
                      backgroundColor: accent.withValues(alpha: .12),
                      color: accent,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 12),
            Column(children: [
              Text('${place.totalCheckins}',
                  style: const TextStyle(
                      fontWeight: FontWeight.w900, fontSize: 18)),
              Text(AppLocale.of(context).t('popular_places_checkin'),
                  style:
                      const TextStyle(color: ArucadColors.muted, fontSize: 10)),
            ]),
          ]),
        ),
      ),
    );
  }
}
