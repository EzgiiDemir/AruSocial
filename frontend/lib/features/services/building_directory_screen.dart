import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/config/campus_sites.dart';
import 'package:arucad_campus_prototype/core/config/poi_config.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/map/in_app_navigation_screen.dart';
import 'package:arucad_campus_prototype/features/map/maplibre_campus_map.dart';

/// Building → Floor → Room → Person browser for students, backed by a real
/// map of ARUCAD's verified locations (the same coordinates the live campus
/// map uses) — so even before an admin has entered any room/person data, a
/// student can still see and navigate to every real building. Rendered on
/// our real OpenStreetMap vector map (MapLibre + OpenFreeMap); the old
/// Google-Maps-only 3D tilt isn't reproduced here since it isn't real
/// building-footprint data, just the SDK's own generic city-model rendering
/// — a flat, honest top-down view of real coordinates is preferred over a
/// fake 3D look.
class BuildingDirectoryScreen extends StatefulWidget {
  final CampusRepository repository;
  const BuildingDirectoryScreen({super.key, required this.repository});

  @override
  State<BuildingDirectoryScreen> createState() => _BuildingDirectoryScreenState();
}

class _BuildingDirectoryScreenState extends State<BuildingDirectoryScreen> {
  Poi? _selectedPoi;
  CampusSite? _selectedSite;

  void _openNavigation(String name, double lat, double lng) {
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) =>
            InAppNavigationScreen(destinationName: name, destination: GeoPoint(lat, lng))));
  }

  @override
  Widget build(BuildContext context) {
    final extentPoints = [
      for (final site in campusSites) GeoPoint(site.lat, site.lng),
      for (final poi in pois) GeoPoint(poi.lat, poi.lng),
    ];
    final markers = <CampusMapMarker>[
      for (final site in campusSites)
        CampusMapMarker(
          id: 'site-${site.id}',
          position: GeoPoint(site.lat, site.lng),
          label: site.name,
          color: ArucadColors.blue,
          emphasized: true,
          onTap: () => setState(() {
            _selectedSite = site;
            _selectedPoi = null;
          }),
        ),
      for (final poi in pois)
        CampusMapMarker(
          id: 'poi-${poi.name}',
          position: GeoPoint(poi.lat, poi.lng),
          label: poi.name,
          color: ArucadColors.slate,
          onTap: () => setState(() {
            _selectedPoi = poi;
            _selectedSite = null;
          }),
        ),
    ];

    return Scaffold(
      appBar: AppBar(title: const Text('Bina Dizini')),
      body: FutureBuilder<List<DirectoryEntry>>(
        future: widget.repository.getDirectoryEntries(),
        builder: (context, snap) {
          final entries = snap.data ?? const <DirectoryEntry>[];
          final byBuilding = <String, List<DirectoryEntry>>{};
          for (final e in entries) {
            byBuilding.putIfAbsent(e.building, () => []).add(e);
          }
          final buildings = byBuilding.keys.toList()..sort();

          return ListView(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
            children: [
              ClipRRect(
                borderRadius: BorderRadius.circular(22),
                child: SizedBox(
                  height: 260,
                  child: Stack(children: [
                    Positioned.fill(
                      child: CampusMapView(extentPoints: extentPoints, markers: markers),
                    ),
                    if (_selectedPoi != null || _selectedSite != null)
                      Positioned(
                        left: 12,
                        right: 12,
                        bottom: 12,
                        child: _MapSelectionCard(
                          title: _selectedPoi?.name ?? _selectedSite!.name,
                          subtitle: _selectedPoi?.category,
                          onNavigate: () {
                            if (_selectedPoi != null) {
                              _openNavigation(
                                  _selectedPoi!.name, _selectedPoi!.lat, _selectedPoi!.lng);
                            } else {
                              _openNavigation(
                                  _selectedSite!.name, _selectedSite!.lat, _selectedSite!.lng);
                            }
                          },
                        ),
                      ),
                  ]),
                ),
              ),
              const SizedBox(height: 8),
              const Text(
                'Kampüsün gerçek konumları — bir noktaya dokun, ardından yol tarifi al.',
                style: TextStyle(color: ArucadColors.muted, fontSize: 12),
              ),
              const SizedBox(height: 20),
              if (buildings.isEmpty)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 16),
                  child: Text(
                    'Henüz oda/kişi bilgisi girilmedi.\nGerçek oda ve kişi bilgisi eklendikçe aşağıda görünecek — '
                    'yukarıdaki harita her zaman gerçek bina konumlarını gösterir.',
                    style: TextStyle(color: ArucadColors.muted),
                  ),
                )
              else
                for (final building in buildings) ...[
                  Text(building, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
                  const SizedBox(height: 8),
                  ...byBuilding[building]!.map((e) {
                    final where = [e.floor, e.room].whereType<String>().join(', ');
                    return Card(
                      child: ListTile(
                        leading:
                            const Icon(Icons.meeting_room_outlined, color: ArucadColors.primary),
                        title: Text(e.occupantName,
                            style: const TextStyle(fontWeight: FontWeight.w700)),
                        subtitle: Text([
                          if (where.isNotEmpty) where,
                          if (e.occupantRole != null) e.occupantRole!,
                        ].join(' · ')),
                      ),
                    );
                  }),
                  const SizedBox(height: 20),
                ],
            ],
          );
        },
      ),
    );
  }
}

class _MapSelectionCard extends StatelessWidget {
  final String title;
  final String? subtitle;
  final VoidCallback onNavigate;
  const _MapSelectionCard({required this.title, this.subtitle, required this.onNavigate});

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.fromLTRB(14, 10, 10, 10),
        decoration: BoxDecoration(
          color: Theme.of(context).scaffoldBackgroundColor,
          borderRadius: BorderRadius.circular(16),
          boxShadow: [
            BoxShadow(color: Colors.black.withValues(alpha: .15), blurRadius: 12, offset: const Offset(0, 4)),
          ],
        ),
        child: Row(children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(title,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontWeight: FontWeight.w800)),
                if (subtitle != null)
                  Text(subtitle!, style: const TextStyle(color: ArucadColors.muted, fontSize: 11.5)),
              ],
            ),
          ),
          FilledButton.icon(
            onPressed: onNavigate,
            icon: const Icon(Icons.directions_walk, size: 16),
            label: const Text('Yol Tarifi', style: TextStyle(fontSize: 12.5)),
          ),
        ]),
      );
}
