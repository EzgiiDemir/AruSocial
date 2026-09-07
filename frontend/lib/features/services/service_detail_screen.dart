import 'dart:async';

import 'package:flutter/material.dart';
import 'package:arucad_campus_prototype/core/models/geo_point.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/config/campus_sites.dart';
import 'package:arucad_campus_prototype/core/config/place_tour.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/building_directory_store.dart';
import 'package:arucad_campus_prototype/core/services/chat_realtime_service.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/tour_launcher.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/map/in_app_navigation_screen.dart';
import 'package:arucad_campus_prototype/features/services/appointment_booking_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/block_renderer.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_back_button.dart';

/// Turns a service from a one-line "directory" entry into a real
/// Who/Where/When/How answer. Navigation / 360 use synced directory +
/// Place coordinates — not a hard-coded main-campus mock.
class ServiceDetailScreen extends StatefulWidget {
  final CampusService service;

  /// Accepted for call-site compatibility with screens that pass a repo.
  final CampusRepository? repository;

  /// `help` so the preview questions match the campus-services category.
  final String applicationTargetType;
  const ServiceDetailScreen({
    super.key,
    required this.service,
    this.repository,
    this.applicationTargetType = 'service',
  });

  @override
  State<ServiceDetailScreen> createState() => _ServiceDetailScreenState();
}

class _ServiceDetailScreenState extends State<ServiceDetailScreen> {
  late CampusService _service;
  late Future<List<DirectoryEntry>> _people;
  ChatRealtimeService? _realtime;
  StreamSubscription<List<String>>? _campusChanges;
  bool _refreshing = false;

  CampusPlace? _navPlace;
  String? _tourUrl;
  String? _tourTarget;
  bool _navResolved = false;

  @override
  void initState() {
    super.initState();
    _service = widget.service;
    _people = _directoryPeople();
    unawaited(_resolveNavigation());
    if (widget.repository != null) unawaited(_startRealtime());
  }

  @override
  void dispose() {
    unawaited(_campusChanges?.cancel() ?? Future.value());
    unawaited(_realtime?.dispose() ?? Future.value());
    super.dispose();
  }

  Future<void> _startRealtime() async {
    try {
      final user = await widget.repository!.getMe();
      if (!mounted) return;
      final realtime = ChatRealtimeService.forRepository(widget.repository!);
      _realtime = realtime;
      _campusChanges = realtime.campusChanged.listen((resources) {
        if (resources.contains('services') ||
            resources.contains('directory') ||
            resources.contains('places')) {
          unawaited(_refreshService());
          unawaited(_resolveNavigation());
        }
      });
      await realtime.start(userId: user.id, userName: user.name);
    } catch (_) {
      // REST data remains available when realtime infrastructure is offline.
    }
  }

  Future<void> _refreshService() async {
    if (_refreshing || widget.repository == null) return;
    _refreshing = true;
    try {
      final services = await widget.repository!.getServices();
      CampusService? fresh;
      for (final entry in services) {
        if (entry.id == _service.id) {
          fresh = entry;
          break;
        }
      }
      if (fresh != null && mounted) {
        setState(() {
          _service = fresh!;
          _people = _directoryPeople();
        });
      }
    } catch (_) {
      // Keep the last confirmed view; REST is still available on next visit.
    } finally {
      _refreshing = false;
    }
  }

  Future<List<DirectoryEntry>> _directoryPeople() async {
    try {
      if (widget.repository != null) {
        final all = await widget.repository!.getDirectoryEntries();
        return all
            .where((e) =>
                e.relatedServiceId != null &&
                campusServiceIdsMatch(e.relatedServiceId!, _service.id))
            .toList();
      }
      return await BuildingDirectoryStore.forService(_service.id);
    } catch (_) {
      return const [];
    }
  }

  Future<void> _resolveNavigation() async {
    final repo = widget.repository;
    List<DirectoryEntry> entries = const [];
    List<CampusPlace> places = const [];

    if (repo != null) {
      try {
        entries = await repo.getDirectoryEntries();
      } catch (_) {}
      try {
        places = await repo.getPlaces();
      } catch (_) {}
    } else {
      entries = await BuildingDirectoryStore.entries();
    }

    final related = entries
        .where((e) =>
            e.relatedServiceId != null &&
            campusServiceIdsMatch(e.relatedServiceId!, _service.id))
        .toList();
    final buildingKey = (_service.building ?? '').trim().toLowerCase();
    final byBuilding = buildingKey.isEmpty
        ? const <DirectoryEntry>[]
        : entries
            .where((e) => e.building.trim().toLowerCase() == buildingKey)
            .toList();

    DirectoryEntry? tourEntry;
    for (final pool in [related, byBuilding]) {
      for (final e in pool) {
        if (e.tourUrl != null && e.tourUrl!.trim().isNotEmpty) {
          tourEntry = e;
          break;
        }
      }
      if (tourEntry != null) break;
    }

    CampusPlace? place;
    final candidates = <String>{
      if (_service.building != null) _service.building!,
      ...related.map((e) => e.building),
      ...byBuilding.map((e) => e.building),
    };
    for (final name in candidates) {
      place = placeMatchingLocationTag(places, name);
      if (place != null) break;
    }

    String? tourUrl = tourEntry?.tourUrl;
    String? tourTarget = tourEntry?.tourTarget;
    if ((tourUrl == null || tourUrl.isEmpty) && place != null) {
      final tour = resolvePlaceTour(place);
      tourUrl = tour.url;
      tourTarget = tour.target;
    } else if (place != null &&
        (tourTarget == null || tourTarget.trim().isEmpty) &&
        place.tourTarget != null) {
      tourTarget = place.tourTarget;
    }

    if (!mounted) return;
    setState(() {
      _navPlace = place;
      _tourUrl = tourUrl;
      _tourTarget = tourTarget;
      _navResolved = true;
    });
  }

  Future<void> _contact() async {
    await launchUrl(Uri(
      scheme: 'mailto',
      path: _service.contact,
      query: 'subject=${Uri.encodeComponent(_service.title)}',
    ));
  }

  void _goToCampus(BuildContext context) {
    final place = _navPlace;
    if (place != null) {
      Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => InAppNavigationScreen(
          destinationName: _service.building ?? place.name,
          destination: GeoPoint(place.lat, place.lng),
          repository: widget.repository,
        ),
      ));
      return;
    }

    // Last resort only when Places/directory have no building pin yet.
    final site = campusSites.firstWhere((s) => s.id == 'main',
        orElse: () => campusSites.first);
    ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
      content: Text(
          'Bu birim için kesin bina koordinatı yok; ana kampüse yönlendiriliyor.'),
    ));
    Navigator.of(context).push(MaterialPageRoute(
      builder: (_) => InAppNavigationScreen(
        destinationName: site.name,
        destination: GeoPoint(site.lat, site.lng),
        repository: widget.repository,
      ),
    ));
  }

  void _openTour(BuildContext context) {
    final url = _tourUrl;
    if (url == null || url.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Bu birim için 360° tur bağlantısı henüz yok.')));
      return;
    }
    open360Tour(context, url, tourTarget: _tourTarget, title: _service.title);
  }

  @override
  Widget build(BuildContext context) {
    final location = [_service.building, _service.floor, _service.room]
        .whereType<String>()
        .join(', ');

    return Scaffold(
      appBar: AppBar(
          title: Text(_service.title), leading: const CampusBackButton()),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
            decoration: BoxDecoration(
                color: ArucadColors.primary.withValues(alpha: .1),
                borderRadius: BorderRadius.circular(999)),
            child: Text(_service.category,
                style: const TextStyle(
                    color: ArucadColors.primary, fontWeight: FontWeight.w700)),
          ),
          const SizedBox(height: 14),
          Text(_service.description,
              style: const TextStyle(fontSize: 15, height: 1.4)),
          if (_service.body.isNotEmpty) ...[
            const SizedBox(height: 8),
            BlockRenderer(blocks: _service.body),
          ],
          if (_service.topics.isNotEmpty) ...[
            const SizedBox(height: 24),
            const Text('Ne için yardımcı olabiliriz?',
                style: TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
            const SizedBox(height: 10),
            Wrap(
              spacing: 8,
              runSpacing: 8,
              children: _service.topics
                  .map((t) => Chip(
                        label: Text(t),
                        backgroundColor: ArucadColors.mist,
                        side: BorderSide.none,
                      ))
                  .toList(),
            ),
          ],
          const SizedBox(height: 24),
          const Text('Bilgiler',
              style: TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
          const SizedBox(height: 10),
          _InfoRow(
            icon: Icons.schedule_outlined,
            label: 'Çalışma Saatleri',
            value: _service.hours ?? 'Henüz girilmedi',
            muted: _service.hours == null,
          ),
          _InfoRow(
            icon: Icons.place_outlined,
            label: 'Konum',
            value: location.isNotEmpty ? location : 'Henüz girilmedi',
            muted: location.isEmpty,
          ),
          if (_service.contactPerson != null)
            _InfoRow(
              icon: Icons.person_outline,
              label: 'Yetkili',
              value: _service.contactPerson!,
            ),
          _InfoRow(
            icon: Icons.mail_outline,
            label: 'E-posta',
            value: _service.contact,
          ),
          FutureBuilder<List<DirectoryEntry>>(
            future: _people,
            builder: (context, snap) {
              final people = snap.data ?? const [];
              if (people.isEmpty) return const SizedBox.shrink();
              return Padding(
                padding: const EdgeInsets.only(top: 14),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('İlgili Kişiler / Odalar',
                        style: TextStyle(
                            fontWeight: FontWeight.w900, fontSize: 15)),
                    const SizedBox(height: 10),
                    for (final p in people)
                      _InfoRow(
                        icon: Icons.badge_outlined,
                        label: p.occupantName,
                        value: [
                          [p.building, p.floor, p.room]
                              .whereType<String>()
                              .join(', '),
                          if (p.occupantRole != null) p.occupantRole!,
                        ].where((s) => s.isNotEmpty).join(' · '),
                      ),
                  ],
                ),
              );
            },
          ),
          const SizedBox(height: 28),
          if (widget.repository != null) ...[
            OutlinedButton.icon(
              onPressed: () => Navigator.of(context).push(MaterialPageRoute(
                builder: (_) =>
                    AppointmentBookingScreen(repository: widget.repository!),
              )),
              icon: const Icon(Icons.event_available_outlined),
              label: const Text('Randevu Al'),
            ),
            const SizedBox(height: 10),
            OutlinedButton.icon(
              onPressed: _contact,
              icon: const Icon(Icons.mail_outline),
              label: const Text('İletişime Geç'),
            ),
          ] else
            FilledButton.icon(
              onPressed: _contact,
              icon: const Icon(Icons.mail_outline),
              label: const Text('İletişime Geç'),
            ),
          const SizedBox(height: 10),
          OutlinedButton.icon(
            onPressed: !_navResolved ? null : () => _goToCampus(context),
            icon: const Icon(Icons.directions_walk),
            label: Text(_navPlace != null
                ? 'Birime Git${_service.building != null ? ' (${_service.building})' : ''}'
                : 'Kampüse Git'),
          ),
          const SizedBox(height: 10),
          OutlinedButton.icon(
            onPressed: !_navResolved ? null : () => _openTour(context),
            icon: const Icon(Icons.threed_rotation),
            label: const Text('360° Tur'),
          ),
          if (_navResolved &&
              (_navPlace == null || _tourUrl == null) &&
              (location.isEmpty || _service.hours == null)) ...[
            const SizedBox(height: 20),
            Text(
              'Not: konum ve/veya 360 bağlantısı directory senkronundan gelmediyse '
              'Yönetim Paneli veya `php artisan campus:sync-360-directory` ile güncellenir.',
              style: TextStyle(color: ArucadColors.muted, fontSize: 12),
            ),
          ],
        ],
      ),
    );
  }
}

class _InfoRow extends StatelessWidget {
  final IconData icon;
  final String label;
  final String value;
  final bool muted;

  const _InfoRow({
    required this.icon,
    required this.label,
    required this.value,
    this.muted = false,
  });

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Icon(icon, size: 18, color: ArucadColors.muted),
            const SizedBox(width: 10),
            Expanded(
              child: RichText(
                text: TextSpan(
                  style:
                      const TextStyle(color: ArucadColors.ink, fontSize: 13.5),
                  children: [
                    TextSpan(
                        text: '$label: ',
                        style: const TextStyle(fontWeight: FontWeight.w700)),
                    TextSpan(
                        text: value,
                        style: TextStyle(
                            color:
                                muted ? ArucadColors.muted : ArucadColors.ink)),
                  ],
                ),
              ),
            ),
          ],
        ),
      );
}
