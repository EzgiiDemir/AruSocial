import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/home/event_join_sheet.dart';
import 'package:arucad_campus_prototype/features/place/place_detail_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/block_renderer.dart';

/// Real event detail — who's organizing it, what it's about, when/where —
/// instead of a card that only offers "Katıl" with no context.
class EventDetailScreen extends StatefulWidget {
  final CampusEvent event;
  final CampusRepository repository;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;
  const EventDetailScreen({
    super.key,
    required this.event,
    required this.repository,
    required this.mapProvider,
    required this.analyticsTracker,
  });

  @override
  State<EventDetailScreen> createState() => _EventDetailScreenState();
}

class _EventDetailScreenState extends State<EventDetailScreen> {
  bool _joined = false;
  CampusPlace? _venue;

  @override
  void initState() {
    super.initState();
    _resolveVenue();
  }

  /// The venue card below reuses the real place data (rating, live density,
  /// reviews) instead of inventing separate "open/closed" fields the app
  /// doesn't actually track — matched by name since events only store a
  /// free-text placeName, not a place id.
  Future<void> _resolveVenue() async {
    final places = await widget.repository.getPlaces();
    if (!mounted) return;
    final name = widget.event.placeName.toLowerCase();
    for (final place in places) {
      if (place.name.toLowerCase() == name) {
        setState(() => _venue = place);
        return;
      }
    }
  }

  Future<void> _join() async {
    final result = await showEventJoinSheet(context, widget.repository, widget.event);
    if (!mounted || result == null) return;
    setState(() => _joined = true);
  }

  @override
  Widget build(BuildContext context) {
    final event = widget.event;
    return Scaffold(
      appBar: AppBar(title: Text(event.title)),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
            decoration: BoxDecoration(
                color: ArucadColors.primary.withValues(alpha: .1),
                borderRadius: BorderRadius.circular(999)),
            child: Text(event.category,
                style: const TextStyle(color: ArucadColors.primary, fontWeight: FontWeight.w700)),
          ),
          const SizedBox(height: 14),
          Text(event.title,
              style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 22)),
          const SizedBox(height: 8),
          _InfoRow(icon: Icons.schedule_outlined, label: 'Saat', value: event.time),
          _InfoRow(icon: Icons.place_outlined, label: 'Yer', value: event.placeName),
          _InfoRow(
              icon: Icons.groups_outlined,
              label: 'Katılımcı',
              value: '${event.attendees} kişi gidiyor'),
          if (event.organizer.isNotEmpty)
            _InfoRow(icon: Icons.badge_outlined, label: 'Organizatör', value: event.organizer),
          _InfoRow(icon: Icons.bolt_outlined, label: 'Ödül', value: '+${event.xp} XP'),
          if (event.audience != 'Tümü')
            _InfoRow(icon: Icons.groups_2_outlined, label: 'Hedef Kitle', value: event.audience),
          if (event.description.isNotEmpty) ...[
            const SizedBox(height: 20),
            const Text('Hakkında', style: TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
            const SizedBox(height: 8),
            Text(event.description, style: const TextStyle(fontSize: 15, height: 1.4)),
          ],
          if (event.body.isNotEmpty) ...[
            const SizedBox(height: 8),
            BlockRenderer(blocks: event.body),
          ],
          if (_venue != null) ...[
            const SizedBox(height: 20),
            Card(
              child: InkWell(
                borderRadius: BorderRadius.circular(18),
                onTap: () => Navigator.of(context).push(MaterialPageRoute(
                    builder: (_) => PlaceDetailScreen(
                        place: _venue!,
                        repository: widget.repository,
                        mapProvider: widget.mapProvider,
                        analyticsTracker: widget.analyticsTracker))),
                child: Padding(
                  padding: const EdgeInsets.all(14),
                  child: Row(children: [
                    Container(
                      width: 44,
                      height: 44,
                      decoration: BoxDecoration(
                          color: ArucadColors.primary.withValues(alpha: .1),
                          borderRadius: BorderRadius.circular(14)),
                      child: const Icon(Icons.place_outlined, color: ArucadColors.primary),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('Mekanı Gör · ${_venue!.name}',
                              style: const TextStyle(fontWeight: FontWeight.w800)),
                          const SizedBox(height: 3),
                          Text(
                              '⭐ ${_venue!.rating} · ${_venue!.density} · yorumlar, 360° tur ve yol tarifi',
                              style: const TextStyle(
                                  color: ArucadColors.muted, fontSize: 12)),
                        ],
                      ),
                    ),
                    const Icon(Icons.chevron_right),
                  ]),
                ),
              ),
            ),
          ],
          const SizedBox(height: 28),
          SizedBox(
            width: double.infinity,
            child: FilledButton(
              onPressed: _joined ? null : _join,
              child: Text(_joined ? 'Katıldın' : 'Katıl'),
            ),
          ),
        ],
      ),
    );
  }
}

class _InfoRow extends StatelessWidget {
  final IconData icon;
  final String label;
  final String value;
  const _InfoRow({required this.icon, required this.label, required this.value});

  @override
  Widget build(BuildContext context) => Padding(
        padding: const EdgeInsets.only(bottom: 10),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(icon, size: 18, color: ArucadColors.muted),
          const SizedBox(width: 10),
          Expanded(
            child: RichText(
              text: TextSpan(
                style: const TextStyle(color: ArucadColors.ink, fontSize: 13.5),
                children: [
                  TextSpan(text: '$label: ', style: const TextStyle(fontWeight: FontWeight.w700)),
                  TextSpan(text: value),
                ],
              ),
            ),
          ),
        ]),
      );
}
