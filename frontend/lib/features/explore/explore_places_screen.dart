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
      appBar: AppBar(title: Text(strings.t('discover_places'))),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : LayoutBuilder(builder: (context, constraints) {
              final isWide = constraints.maxWidth >= 700;
              return RefreshIndicator(
                onRefresh: _load,
                child: CustomScrollView(
                  slivers: [
                    SliverToBoxAdapter(
                      child: Padding(
                        padding: const EdgeInsets.fromLTRB(20, 12, 20, 0),
                        child: _BuildingDirectoryEntryCard(
                            onTap: _openBuildingDirectory),
                      ),
                    ),
                    SliverToBoxAdapter(
                      child: Padding(
                        padding: const EdgeInsets.fromLTRB(20, 14, 12, 8),
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
                    ),
                    SliverToBoxAdapter(
                      child: SizedBox(
                        height: 42,
                        child: ListView(
                          scrollDirection: Axis.horizontal,
                          padding: const EdgeInsets.fromLTRB(20, 0, 20, 10),
                          children: [
                            // SelectableChip, not a bare ChoiceChip: it is
                            // the one that flips the label to white on the
                            // dark selected fill. A plain ChoiceChip keeps
                            // its dark default label and becomes unreadable
                            // on the navy background once selected.
                            Padding(
                              padding: const EdgeInsets.only(right: 8),
                              child: SelectableChip(
                                label: strings.t('category_all'),
                                selected: _category == null,
                                onSelected: (_) => setState(() {
                                  _category = null;
                                  _resetVisible();
                                }),
                              ),
                            ),
                            for (final cat in _categories)
                              Padding(
                                padding: const EdgeInsets.only(right: 8),
                                child: SelectableChip(
                                  label: cat,
                                  selected: _category == cat,
                                  onSelected: (_) => setState(() {
                                    _category = cat;
                                    _resetVisible();
                                  }),
                                ),
                              ),
                          ],
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
                    else if (isWide)
                      SliverPadding(
                        padding: const EdgeInsets.fromLTRB(20, 4, 20, 28),
                        sliver: SliverList.builder(
                          itemCount: (page.length / 2).ceil() + 1,
                          itemBuilder: (context, row) {
                            if (row == (page.length / 2).ceil()) {
                              return LoadMoreButton(
                                shown: shown,
                                total: filtered.length,
                                itemLabel: 'yer',
                                onTap: () =>
                                    setState(() => _visible += kPageSize),
                              );
                            }
                            final left = page[row * 2];
                            final rightIndex = row * 2 + 1;
                            final right =
                                rightIndex < page.length ? page[rightIndex] : null;
                            return Padding(
                              padding: EdgeInsets.only(top: row == 0 ? 0 : 10),
                              child: Row(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Expanded(
                                    child: PlaceCard(
                                      place: left,
                                      events: _events,
                                      checkInCount:
                                          _checkInCounts[left.id] ?? 0,
                                      distanceLabel: _liveDistanceLabel(left),
                                      accentColor: brandAccentAt(row * 2),
                                      onOpen: () => _openPlace(left),
                                    ),
                                  ),
                                  const SizedBox(width: 10),
                                  Expanded(
                                    child: right == null
                                        ? const SizedBox.shrink()
                                        : PlaceCard(
                                            place: right,
                                            events: _events,
                                            checkInCount:
                                                _checkInCounts[right.id] ?? 0,
                                            distanceLabel:
                                                _liveDistanceLabel(right),
                                            accentColor:
                                                brandAccentAt(rightIndex),
                                            onOpen: () => _openPlace(right),
                                          ),
                                  ),
                                ],
                              ),
                            );
                          },
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
              );
            }),
    );
  }
}

class _BuildingDirectoryEntryCard extends StatelessWidget {
  final VoidCallback onTap;
  const _BuildingDirectoryEntryCard({required this.onTap});

  @override
  Widget build(BuildContext context) {
    return Material(
      color: ArucadColors.paper,
      borderRadius: BorderRadius.circular(18),
      child: InkWell(
        borderRadius: BorderRadius.circular(18),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(children: [
            Container(
              width: 46,
              height: 46,
              decoration: BoxDecoration(
                  color: ArucadColors.primary.withValues(alpha: .12),
                  borderRadius: BorderRadius.circular(14)),
              child: const Icon(Icons.apartment_outlined,
                  color: ArucadColors.primary),
            ),
            const SizedBox(width: 12),
            const Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Binalar & 360° Tur',
                      style: TextStyle(fontWeight: FontWeight.w900, fontSize: 14.5)),
                  SizedBox(height: 2),
                  Text('Bina, kat ve odalara göz at',
                      style: TextStyle(color: ArucadColors.muted, fontSize: 12)),
                ],
              ),
            ),
            const Icon(Icons.chevron_right, color: ArucadColors.muted),
          ]),
        ),
      ),
    );
  }
}
