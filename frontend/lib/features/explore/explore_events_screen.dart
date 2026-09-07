import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

class ExploreEventsScreen extends StatefulWidget {
  final CampusRepository repository;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;

  const ExploreEventsScreen({
    super.key,
    required this.repository,
    required this.mapProvider,
    required this.analyticsTracker,
  });

  @override
  State<ExploreEventsScreen> createState() => _ExploreEventsScreenState();
}

class _ExploreEventsScreenState extends State<ExploreEventsScreen> {
  bool _loading = true;
  List<CampusEvent> _events = const [];
  String _query = '';
  String? _category; // null = all
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
      final events = await widget.repository.getEvents();
      if (!mounted) return;
      setState(() {
        _events = events;
        _loading = false;
        _visible = kPageSize;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _loading = false);
    }
  }

  void _resetVisible() => _visible = kPageSize;

  List<String> get _categories {
    final found = <String>{};
    for (final e in _events) {
      final c = e.category.trim();
      if (c.isNotEmpty) found.add(c);
    }
    final list = found.toList()..sort();
    return list;
  }

  List<CampusEvent> get _filtered {
    final q = _query.trim().toLowerCase();
    return _events.where((e) {
      if (_category != null && e.category != _category) return false;
      if (q.isEmpty) return true;
      return e.title.toLowerCase().contains(q) ||
          e.placeName.toLowerCase().contains(q) ||
          e.category.toLowerCase().contains(q) ||
          e.description.toLowerCase().contains(q);
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
                child: Text(strings.t('explore_events_filter'),
                    style: const TextStyle(
                        fontWeight: FontWeight.w900, fontSize: 16)),
              ),
              ListTile(
                title: Text(strings.t('category_all'),
                    style: TextStyle(
                        fontWeight: _category == null
                            ? FontWeight.w800
                            : FontWeight.w500)),
                trailing: _category == null
                    ? const Icon(Icons.check, color: ArucadColors.primary)
                    : null,
                onTap: () => Navigator.pop(ctx, ''),
              ),
              for (final cat in _categories)
                ListTile(
                  title: Text(cat,
                      style: TextStyle(
                          fontWeight: _category == cat
                              ? FontWeight.w800
                              : FontWeight.w500)),
                  trailing: _category == cat
                      ? const Icon(Icons.check, color: ArucadColors.primary)
                      : null,
                  onTap: () => Navigator.pop(ctx, cat),
                ),
              const SizedBox(height: 8),
            ],
          ),
        );
      },
    );
    if (!mounted || selected == null) return;
    setState(() {
      _category = selected.isEmpty ? null : selected;
      _resetVisible();
    });
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final filtered = _filtered;
    final shown = _visible.clamp(0, filtered.length);
    final visible = filtered.take(shown).toList();

    return Scaffold(
      appBar: AppBar(title: Text(strings.t('discover_creative'))),
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
                                hintText: strings.t('explore_events_search'),
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
                            tooltip: strings.t('explore_events_filter'),
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
                  if (_category != null)
                    SliverToBoxAdapter(
                      child: Padding(
                        padding: const EdgeInsets.fromLTRB(20, 0, 20, 8),
                        child: Align(
                          alignment: Alignment.centerLeft,
                          child: InputChip(
                            label: Text(_category!),
                            deleteIcon: const Icon(Icons.close, size: 16),
                            onDeleted: () => setState(() {
                              _category = null;
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
                        child: Text(strings.t('explore_events_empty'),
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
                              itemLabel: 'etkinlik',
                              onTap: () =>
                                  setState(() => _visible += kPageSize),
                            );
                          }
                          return Padding(
                            padding: EdgeInsets.only(top: i == 0 ? 0 : 10),
                            child: EventCard(
                              event: visible[i],
                              accentColor: brandAccentAt(i),
                              repository: widget.repository,
                              mapProvider: widget.mapProvider,
                              analyticsTracker: widget.analyticsTracker,
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
