import 'dart:async';

import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';

import 'package:arucad_campus_prototype/core/config/place_catalog.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/location_service.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/place/place_detail_screen.dart';
import 'package:arucad_campus_prototype/features/services/building_directory_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

class ExplorePlacesScreen extends StatefulWidget {
  final CampusRepository repository;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;

  const ExplorePlacesScreen({
    super.key,
    required this.repository,
    required this.mapProvider,
    required this.analyticsTracker,
  });

  @override
  State<ExplorePlacesScreen> createState() => _ExplorePlacesScreenState();
}

class _ExplorePlacesScreenState extends State<ExplorePlacesScreen>
    with WidgetsBindingObserver {
  bool _loading = true;
  List<CampusPlace> _places = const [];
  List<CampusEvent> _events = const [];
  Map<String, int> _checkInCounts = const {};
  Position? _myPosition;
  String _query = '';
  String? _category;
  int _visible = kPageSize;
  final _searchController = TextEditingController();
  final _searchFocus = FocusNode();
  StreamSubscription<Position>? _positionSub;
  StreamSubscription<void>? _grantedSub;
  static const _location = LocationService();

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _load();
    _grantedSub = LocationService.onGranted.listen((_) {
      unawaited(_attachLiveLocation());
    });
    unawaited(_attachLiveLocation());
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      unawaited(_resolvePositionOnResume());
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    unawaited(_positionSub?.cancel() ?? Future.value());
    unawaited(_grantedSub?.cancel() ?? Future.value());
    _searchController.dispose();
    _searchFocus.dispose();
    super.dispose();
  }

  void _resetVisible() => _visible = kPageSize;

  Future<void> _load() async {
    Future<T> one<T>(Future<T> future, T fallback) async {
      try {
        return await future;
      } catch (_) {
        return fallback;
      }
    }

    final places = uniqueCampusPlaces(
        await one(widget.repository.getPlaces(), const <CampusPlace>[]));
    final events =
        await one(widget.repository.getEvents(), const <CampusEvent>[]);
    final feed = await one(widget.repository.getFeed(), const <FeedPost>[]);
    if (!mounted) return;
    final counts = <String, int>{};
    for (final place in places) {
      final name = place.name.toLowerCase();
      counts[place.id] =
          feed.where((p) => p.text.toLowerCase().contains(name)).length;
    }
    setState(() {
      _places = places;
      _events = events;
      _checkInCounts = counts;
      _loading = false;
      _visible = kPageSize;
    });
  }

  Future<void> _resolvePositionOnResume() async {
    if (!await _location.hasGranted()) return;
    await _resolvePosition();
    if (_positionSub == null) await _listenPositionStream();
  }

  Future<void> _attachLiveLocation() async {
    if (!await _location.hasGranted()) return;
    await _resolvePosition();
    await _listenPositionStream();
  }

  Future<void> _resolvePosition() async {
    try {
      final position = await _location.getCurrentPositionIfGranted();
      if (position == null || !mounted) return;
      setState(() => _myPosition = position);
    } catch (_) {}
  }

  Future<void> _listenPositionStream() async {
    if (_positionSub != null) return;
    if (!await _location.hasGranted()) return;
    _positionSub = _location.positionStream().listen(
      (position) {
        if (!mounted) return;
        setState(() => _myPosition = position);
      },
      onError: (_) {},
    );
  }

  String? _liveDistanceLabel(CampusPlace place) {
    final pos = _myPosition;
    if (pos == null) return null;
    final meters = Geolocator.distanceBetween(
        pos.latitude, pos.longitude, place.lat, place.lng);
    if (meters < 1000) return '${meters.round()} m uzakta';
    return '${(meters / 1000).toStringAsFixed(1)} km uzakta';
  }

  List<String> get _categories {
    final found = <String>{};
    for (final p in _places) {
      final c = p.category.trim();
      if (c.isNotEmpty) found.add(c);
    }
    final list = found.toList()..sort();
    return list;
  }

  List<CampusPlace> get _filtered {
    final q = _query.trim().toLowerCase();
    return _places.where((p) {
      if (_category != null && p.category != _category) return false;
      if (q.isEmpty) return true;
      return p.name.toLowerCase().contains(q) ||
          p.category.toLowerCase().contains(q) ||
          p.street.toLowerCase().contains(q);
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
                child: Text(strings.t('explore_places_filter'),
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

  void _openPlace(CampusPlace place) {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => PlaceDetailScreen(
          place: place,
          repository: widget.repository,
          mapProvider: widget.mapProvider,
          analyticsTracker: widget.analyticsTracker,
        ),
      ),
    );
  }

  void _openBuildingDirectory() {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => BuildingDirectoryScreen(
          repository: widget.repository,
          mapProvider: widget.mapProvider,
          analyticsTracker: widget.analyticsTracker,
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final filtered = _filtered;
    final shown = _visible.clamp(0, filtered.length);
    final page = filtered.take(shown).toList();

    return Scaffold(
      appBar: AppBar(
        title: Text(strings.t('discover_places')),
        actions: [
          TextButton.icon(
            onPressed: _openBuildingDirectory,
            icon: const Icon(Icons.apartment_outlined, size: 18),
            label: const Text('Binalar & 360°'),
          ),
        ],
      ),
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
                                hintText: strings.t('explore_places_search'),
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
                            tooltip: strings.t('explore_places_filter'),
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
                        child: Text(strings.t('explore_places_empty'),
                            style: const TextStyle(color: ArucadColors.muted)),
                      ),
                    )
                  else
                    SliverPadding(
                      padding: const EdgeInsets.fromLTRB(20, 4, 20, 28),
                      sliver: SliverList.builder(
                        itemCount: page.length + 1,
                        itemBuilder: (context, i) {
                          if (i == page.length) {
                            return LoadMoreButton(
                              shown: shown,
                              total: filtered.length,
                              itemLabel: 'yer',
                              onTap: () =>
                                  setState(() => _visible += kPageSize),
                            );
                          }
                          final place = page[i];
                          return Padding(
                            padding: EdgeInsets.only(top: i == 0 ? 0 : 10),
                            child: PlaceCard(
                              place: place,
                              events: _events,
                              checkInCount: _checkInCounts[place.id] ?? 0,
                              distanceLabel: _liveDistanceLabel(place),
                              accentColor: brandAccentAt(i),
                              onOpen: () => _openPlace(place),
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
