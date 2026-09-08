import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/place/place_detail_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// Where people actually are right now, ranked by real recent check-ins.
///
/// This answers a different question from "what is nearest": students open
/// it to find somewhere already busy — a studio with people in it beats an
/// empty room twenty metres closer. The top five get the podium treatment;
/// the rest continue as a plain ranked list.
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
  List<CampusEvent> _events = const [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    List<CampusPlace> places = const [];
    List<CampusEvent> events = const [];
    try {
      places = await widget.repository.getPlaces();
    } catch (_) {}
    try {
      events = await widget.repository.getEvents();
    } catch (_) {}

    final ranked = [...places]
      ..sort((a, b) => b.recentCheckins.compareTo(a.recentCheckins));

    if (!mounted) return;
    setState(() {
      _ranked = ranked.where((p) => p.recentCheckins > 0).toList();
      _events = events;
      _loading = false;
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

  @override
  Widget build(BuildContext context) {
    final top = _ranked.take(5).toList();
    final rest = _ranked.skip(5).toList();

    return Scaffold(
      appBar: AppBar(title: const Text('En Popüler Yerler')),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: _ranked.isEmpty
                  ? ListView(children: const [
                      SizedBox(height: 120),
                      Icon(Icons.local_fire_department_outlined,
                          size: 44, color: ArucadColors.muted),
                      SizedBox(height: 12),
                      Center(
                        child: Padding(
                          padding: EdgeInsets.symmetric(horizontal: 40),
                          child: Text(
                            'Henüz check-in yok. Bir yere gidip check-in '
                            'yapan ilk kişi sen ol.',
                            textAlign: TextAlign.center,
                            style: TextStyle(color: ArucadColors.muted),
                          ),
                        ),
                      ),
                    ])
                  : ListView(
                      padding: const EdgeInsets.fromLTRB(20, 16, 20, 28),
                      children: [
                        const Text(
                          'Şu an en çok check-in yapılan yerler',
                          style: TextStyle(
                              color: ArucadColors.muted, fontSize: 12.5),
                        ),
                        const SizedBox(height: 14),
                        for (var i = 0; i < top.length; i++)
                          Padding(
                            padding: EdgeInsets.only(top: i == 0 ? 0 : 10),
                            child: _PopularPlaceCard(
                              rank: i + 1,
                              place: top[i],
                              busiest: _ranked.first.recentCheckins,
                              onTap: () => _open(top[i]),
                            ),
                          ),
                        if (rest.isNotEmpty) ...[
                          const SizedBox(height: 22),
                          const Text('Diğer yerler',
                              style: TextStyle(
                                  fontWeight: FontWeight.w900, fontSize: 15)),
                          const SizedBox(height: 8),
                          for (final place in rest)
                            PlaceCard(
                              place: place,
                              events: _events,
                              checkInCount: place.recentCheckins,
                              onOpen: () => _open(place),
                            ),
                        ],
                      ],
                    ),
            ),
    );
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

  /// Gold / silver / bronze for the top three, brand navy below that.
  Color get _rankColor => switch (rank) {
        1 => const Color(0xFFD4A017),
        2 => const Color(0xFF9AA5B1),
        3 => const Color(0xFFB07A4B),
        _ => ArucadColors.primary,
      };

  @override
  Widget build(BuildContext context) {
    final (densityColor, densityLabel) = campusDensityInfo(place);
    final share = busiest <= 0 ? 0.0 : (place.recentCheckins / busiest).clamp(0.0, 1.0);

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
                color: _rankColor.withValues(alpha: .14),
                borderRadius: BorderRadius.circular(11),
              ),
              child: Text('$rank',
                  style: TextStyle(
                      fontWeight: FontWeight.w900,
                      fontSize: 15,
                      color: _rankColor)),
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
                    place.category.isEmpty ? densityLabel : place.category,
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
                      backgroundColor: densityColor.withValues(alpha: .12),
                      color: densityColor,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 12),
            Column(children: [
              Text('${place.recentCheckins}',
                  style: const TextStyle(
                      fontWeight: FontWeight.w900, fontSize: 18)),
              const Text('check-in',
                  style: TextStyle(color: ArucadColors.muted, fontSize: 10)),
            ]),
          ]),
        ),
      ),
    );
  }
}
