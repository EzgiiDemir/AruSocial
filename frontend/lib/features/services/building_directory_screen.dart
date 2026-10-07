import 'dart:async';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/config/place_tour.dart';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/campus_directory.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/tour_launcher.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/map/campus_map_launcher.dart';
import 'package:arucad_campus_prototype/features/map/in_app_navigation_screen.dart';
import 'package:arucad_campus_prototype/features/home/campus_live_map.dart';

/// Building → Floor → Room drill-down. Campus map canvas lives in the hub —
/// this screen deep-links instead of embedding a second MapLibre view.
class BuildingDirectoryScreen extends StatefulWidget {
  final CampusRepository repository;
  final MapProvider? mapProvider;
  final AnalyticsTracker? analyticsTracker;
  final VoidCallback? onOpenGalatea;
  final CampusVisibility initialVisibility;

  const BuildingDirectoryScreen({
    super.key,
    required this.repository,
    this.mapProvider,
    this.analyticsTracker,
    this.onOpenGalatea,
    this.initialVisibility = CampusVisibility.friends,
  });

  @override
  State<BuildingDirectoryScreen> createState() =>
      _BuildingDirectoryScreenState();
}

class _BuildingDirectoryScreenState extends State<BuildingDirectoryScreen> {
  String _query = '';
  String? _building;
  String? _floor;
  late Future<List<CampusBuilding>> _buildingsFuture;
  late Future<List<DirectoryEntry>> _directoryFuture;
  Future<List<CampusFloor>>? _floorsFuture;
  Future<List<CampusRoom>>? _roomsFuture;
  List<CampusPlace> _places = const [];
  List<CampusEvent> _events = const [];

  @override
  void initState() {
    super.initState();
    _buildingsFuture = widget.repository.getDirectoryBuildings();
    _directoryFuture = widget.repository.getDirectoryEntries();
    unawaited(_loadMapContext());
  }

  Future<void> _loadMapContext() async {
    try {
      final results = await Future.wait([
        widget.repository.getPlaces(),
        widget.repository.getEvents(),
      ]);
      if (!mounted) return;
      setState(() {
        _places = results[0] as List<CampusPlace>;
        _events = results[1] as List<CampusEvent>;
      });
    } catch (_) {}
  }

  void _openNavigation(String name, double lat, double lng) {
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => InAppNavigationScreen(
              destinationName: name,
              destination: GeoPoint(lat, lng),
              repository: widget.repository,
            )));
  }

  void _openCampusMap({String? focusPlaceId}) {
    final mapProvider = widget.mapProvider;
    final analytics = widget.analyticsTracker;
    if (mapProvider == null || analytics == null) {
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(AppLocale.of(context).t('bd_use_map'))));
      return;
    }
    openCampusMapHub(
      context,
      places: _places,
      events: _events,
      repository: widget.repository,
      mapProvider: mapProvider,
      analyticsTracker: analytics,
      onOpenGalatea: widget.onOpenGalatea ?? () {},
      initialVisibility: widget.initialVisibility,
      focusPlaceId: focusPlaceId,
    );
  }

  CampusPlace? _placeNearBuilding(String buildingName) {
    return campusPlaceForDirectoryBuilding(_places, buildingName);
  }

  String _searchKey(String value) => value
      .toLowerCase()
      .replaceAll('ı', 'i')
      .replaceAll('ğ', 'g')
      .replaceAll('ü', 'u')
      .replaceAll('ş', 's')
      .replaceAll('ö', 'o')
      .replaceAll('ç', 'c')
      .trim();

  bool _entryMatches(DirectoryEntry entry) {
    final needle = _searchKey(_query);
    return <String>[
      entry.building,
      entry.floor ?? '',
      entry.room ?? '',
      entry.occupantName,
      entry.occupantRole ?? '',
      entry.categoryName ?? '',
      entry.roomNumber ?? '',
      entry.campusName ?? '',
    ].any((value) => _searchKey(value).contains(needle));
  }

  void _openEntryNavigation(DirectoryEntry entry) {
    final place = _placeNearBuilding(entry.building);
    if (place == null) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
        content: Text('Bu bina için doğrulanmış harita koordinatı bulunamadı.'),
      ));
      return;
    }
    _openNavigation(
      (entry.room ?? entry.occupantName).trim().isEmpty
          ? entry.building
          : (entry.room ?? entry.occupantName),
      place.lat,
      place.lng,
    );
  }

  void _selectBuilding(String building) {
    setState(() {
      _building = building;
      _floor = null;
      _floorsFuture = widget.repository.getDirectoryFloors(building);
      _roomsFuture = null;
    });
  }

  void _selectFloor(String floor) {
    final building = _building;
    if (building == null) return;
    setState(() {
      _floor = floor;
      _roomsFuture = widget.repository.getDirectoryRooms(building, floor);
    });
  }

  void _backToBuildings() {
    setState(() {
      _building = null;
      _floor = null;
      _floorsFuture = null;
      _roomsFuture = null;
    });
  }

  void _backToFloors() {
    setState(() {
      _floor = null;
      _roomsFuture = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(_floor != null
            ? '$_building · $_floor'
            : _building != null
                ? _building!
                : 'Binalar ve 360° Tur'),
        leading: _building != null
            ? IconButton(
                icon: const Icon(Icons.arrow_back),
                onPressed: _floor != null ? _backToFloors : _backToBuildings,
              )
            : null,
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
        children: [
          Card(
            child: InkWell(
              borderRadius: BorderRadius.circular(20),
              onTap: () {
                final focus = _building == null
                    ? null
                    : _placeNearBuilding(_building!)?.id;
                _openCampusMap(focusPlaceId: focus);
              },
              child: const Padding(
                padding: EdgeInsets.all(16),
                child: Row(children: [
                  Icon(Icons.map_outlined, color: ArucadColors.primary),
                  SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text('Kampüs Haritası',
                            style: TextStyle(fontWeight: FontWeight.w900)),
                        SizedBox(height: 4),
                        Text('Binanın kampüsteki konumunu gör',
                            style: TextStyle(
                                color: ArucadColors.muted, fontSize: 12)),
                      ],
                    ),
                  ),
                  Icon(Icons.chevron_right),
                ]),
              ),
            ),
          ),
          const SizedBox(height: 12),
          Text(
            _building == null
                ? 'Bir bina bul. Turu aç veya kat ve odaları incele.'
                : _floor == null
                    ? 'Odaları görmek için bir kat seç.'
                    : 'Odaya yol tarifi al veya varsa 360° turunu aç.',
            style: const TextStyle(color: ArucadColors.muted, fontSize: 13),
          ),
          if (_building == null) ...[
            const SizedBox(height: 12),
            TextField(
              decoration: const InputDecoration(
                hintText: 'Bina, oda, birim veya personel ara',
                prefixIcon: Icon(Icons.search),
                border: OutlineInputBorder(),
              ),
              onChanged: (value) =>
                  setState(() => _query = value.trim().toLowerCase()),
            ),
          ],
          const SizedBox(height: 16),
          if (_building == null && _query.isNotEmpty)
            FutureBuilder<List<DirectoryEntry>>(
              future: _directoryFuture,
              builder: (context, snap) {
                if (!snap.hasData) {
                  return const Center(child: CircularProgressIndicator());
                }
                final matches =
                    snap.data!.where(_entryMatches).take(40).toList();
                if (matches.isEmpty) return const SizedBox.shrink();
                return Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const Text('Oda ve birim sonuçları',
                        style: TextStyle(fontWeight: FontWeight.w900)),
                    const SizedBox(height: 8),
                    for (final entry in matches)
                      Card(
                        child: ListTile(
                          leading: const Icon(Icons.meeting_room_outlined),
                          title: Text((entry.room ?? '').trim().isNotEmpty
                              ? entry.room!
                              : entry.occupantName),
                          subtitle: Text(<String>[
                            entry.building,
                            if ((entry.floor ?? '').isNotEmpty) entry.floor!,
                            if ((entry.categoryName ?? entry.occupantRole ?? '')
                                .isNotEmpty)
                              (entry.categoryName ?? entry.occupantRole)!,
                            if (entry.occupantName.isNotEmpty &&
                                entry.occupantName != entry.room)
                              entry.occupantName,
                          ].join(' · ')),
                          onTap: () => _openEntryNavigation(entry),
                          trailing:
                              (entry.tourUrl ?? entry.splatSceneUrl) == null
                                  ? const Icon(Icons.directions_outlined)
                                  : IconButton(
                                      tooltip: 'Bu odayı 360° aç',
                                      icon: const Icon(Icons.threesixty),
                                      onPressed: () => open360Tour(
                                        context,
                                        entry.tourUrl ?? entry.splatSceneUrl!,
                                        tourTarget: entry.tourTarget,
                                        title: entry.room ?? entry.occupantName,
                                      ),
                                    ),
                        ),
                      ),
                    const SizedBox(height: 16),
                    const Divider(),
                    const SizedBox(height: 8),
                    const Text('Binalar',
                        style: TextStyle(fontWeight: FontWeight.w900)),
                    const SizedBox(height: 8),
                  ],
                );
              },
            ),
          if (_roomsFuture != null)
            FutureBuilder<List<CampusRoom>>(
              future: _roomsFuture,
              builder: (context, snap) {
                if (snap.hasError) {
                  return Text('Kat bulunamadı: ${snap.error}',
                      style: const TextStyle(color: ArucadColors.danger));
                }
                if (!snap.hasData) {
                  return const Center(child: CircularProgressIndicator());
                }
                final rooms = snap.data!;
                if (rooms.isEmpty) {
                  return const Text('Bu katta oda kaydı yok.',
                      style: TextStyle(color: ArucadColors.muted));
                }
                return Column(
                  children: [
                    for (final room in rooms)
                      ListTile(
                        title: Text(room.title),
                        subtitle: Text([
                          if (room.categoryName != null) room.categoryName!,
                          if (room.roomNumber != null) room.roomNumber!,
                          if (room.occupantRole != null &&
                              room.occupantRole != room.categoryName)
                            room.occupantRole!,
                          if (room.occupantName.isNotEmpty) room.occupantName,
                          if (room.notes != null) room.notes!,
                        ].where((s) => s.isNotEmpty).join(' · ')),
                        trailing:
                            Row(mainAxisSize: MainAxisSize.min, children: [
                          if ((room.tourUrl ?? room.splatSceneUrl) != null)
                            IconButton(
                              tooltip: '360° Tur',
                              icon: const Icon(Icons.threesixty),
                              onPressed: () => open360Tour(
                                context,
                                room.tourUrl ?? room.splatSceneUrl!,
                                tourTarget: room.tourTarget,
                                title: room.occupantName.isNotEmpty
                                    ? room.occupantName
                                    : '360° Tur',
                              ),
                            ),
                          const Icon(Icons.directions_walk),
                        ]),
                        onTap: () {
                          final match = _building == null
                              ? null
                              : _placeNearBuilding(_building!);
                          if (match != null) {
                            _openNavigation(room.title, match.lat, match.lng);
                          } else {
                            ScaffoldMessenger.of(context).showSnackBar(
                                const SnackBar(
                                    content: Text(
                                        'Bu oda için koordinat yok — kampüs haritasını aç.')));
                          }
                        },
                      ),
                  ],
                );
              },
            )
          else if (_floorsFuture != null)
            FutureBuilder<List<CampusFloor>>(
              future: _floorsFuture,
              builder: (context, snap) {
                if (snap.hasError) {
                  return Text('Bina bulunamadı: ${snap.error}',
                      style: const TextStyle(color: ArucadColors.danger));
                }
                if (!snap.hasData) {
                  return const Center(child: CircularProgressIndicator());
                }
                final floors = snap.data!;
                return Column(
                  children: [
                    for (final floor in floors)
                      ListTile(
                        title: Text(floor.name),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: () => _selectFloor(floor.name),
                      ),
                  ],
                );
              },
            )
          else
            FutureBuilder<List<CampusBuilding>>(
              future: _buildingsFuture,
              builder: (context, snap) {
                if (snap.hasError) {
                  return Text('Dizin yüklenemedi: ${snap.error}',
                      style: const TextStyle(color: ArucadColors.danger));
                }
                if (!snap.hasData) {
                  return const Center(child: CircularProgressIndicator());
                }
                final buildings = snap.data!
                    .where(
                        (b) => _searchKey(b.name).contains(_searchKey(_query)))
                    .toList();
                if (buildings.isEmpty) {
                  return const Text('Bina bulunamadı.',
                      style: TextStyle(color: ArucadColors.muted));
                }
                // stretch, not the default centre: a Card sizes to its
                // child, so a building with only the "Katlar ve odalar"
                // button rendered as a narrow card floating in the middle
                // of the list while its neighbours ran full width. The
                // rows are the same object; they should be the same shape.
                return Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    for (final b in buildings)
                      Card(
                        child: Padding(
                          padding: const EdgeInsets.all(16),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(b.name,
                                  style: const TextStyle(
                                      fontWeight: FontWeight.w800,
                                      fontSize: 17)),
                              const SizedBox(height: 12),
                              Wrap(spacing: 8, runSpacing: 8, children: [
                                if (_placeNearBuilding(b.name)
                                    case final place?)
                                  FilledButton.icon(
                                    icon: const Icon(Icons.threesixty),
                                    label: const Text('360° Turu aç'),
                                    onPressed: () {
                                      final tour = resolvePlaceTour(place);
                                      open360Tour(context, tour.url,
                                          tourTarget: tour.target,
                                          title: b.name);
                                    },
                                  ),
                                OutlinedButton.icon(
                                  icon: const Icon(Icons.meeting_room_outlined),
                                  label: const Text('Katlar ve odalar'),
                                  onPressed: () => _selectBuilding(b.name),
                                ),
                              ]),
                            ],
                          ),
                        ),
                      ),
                  ],
                );
              },
            ),
        ],
      ),
    );
  }
}
