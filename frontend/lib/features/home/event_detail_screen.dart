import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/staff_application.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/core/theme/campus_density.dart';
import 'package:arucad_campus_prototype/features/home/event_join_sheet.dart';
import 'package:arucad_campus_prototype/features/place/place_detail_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/block_renderer.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_back_button.dart';

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
    _resolveJoined();
  }

  /// Real bug fix: this used to always start `false`, so reopening an
  /// event you'd already joined earlier showed "Katıl" again — reuses the
  /// same real-activity check `quests_screen.dart`'s `_joinedEventTitles`
  /// already does (no dedicated "am I joined" endpoint exists, but a real
  /// `eventJoin` activity row is a real signal, not a guess).
  Future<void> _resolveJoined() async {
    final results = await Future.wait([
      widget.repository.getMyActivity(),
      widget.repository.getMyApplications(),
    ]);
    if (!mounted) return;
    final activity = results[0] as List<ActivityItem>;
    final apps = results[1] as List<ParticipationApplication>;
    final alreadyJoined = activity.any((a) =>
            a.kind == ActivityKind.eventJoin &&
            a.title.replaceFirst('Katıldın: ', '').toLowerCase() ==
                widget.event.title.toLowerCase()) ||
        apps.any((a) =>
            a.targetType == 'event' &&
            a.targetId == widget.event.id &&
            a.countsAsJoined);
    if (alreadyJoined) setState(() => _joined = true);
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
    final s = AppLocale.of(context);
    final scheme = Theme.of(context).colorScheme;
    return Scaffold(
      appBar: AppBar(title: Text(event.title), leading: const CampusBackButton()),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
        children: [
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
            decoration: BoxDecoration(
                color: ArucadColors.primary.withValues(alpha: .1),
                borderRadius: BorderRadius.circular(ArucadRadius.pill)),
            child: Text(event.category,
                style: const TextStyle(
                    color: ArucadColors.primary, fontWeight: FontWeight.w700)),
          ),
          const SizedBox(height: 14),
          Text(event.title,
              style: TextStyle(
                  fontWeight: FontWeight.w900,
                  fontSize: 22,
                  color: scheme.onSurface)),
          const SizedBox(height: 8),
          _InfoRow(
              icon: Icons.schedule_outlined,
              label: s.t('common_time'),
              value: event.time),
          _InfoRow(
              icon: Icons.place_outlined,
              label: s.t('common_place'),
              value: event.placeName),
          _InfoRow(
              icon: Icons.groups_outlined,
              label: s.t('event_attendees'),
              value: s
                  .t('event_going_count')
                  .replaceAll('{n}', '${event.attendees}')),
          if (event.organizer.isNotEmpty)
            _InfoRow(
                icon: Icons.badge_outlined,
                label: s.t('event_organizer'),
                value: event.organizer),
          _InfoRow(
              icon: Icons.bolt_outlined,
              label: s.t('event_reward'),
              value: '+${event.xp} XP'),
          if (event.audience != 'Tümü')
            _InfoRow(
                icon: Icons.groups_2_outlined,
                label: s.t('event_audience'),
                value: event.audience),
          if (event.description.isNotEmpty) ...[
            const SizedBox(height: 20),
            Text(s.t('common_about'),
                style: TextStyle(
                    fontWeight: FontWeight.w900,
                    fontSize: 15,
                    color: scheme.onSurface)),
            const SizedBox(height: 8),
            Text(event.description,
                style: TextStyle(
                    fontSize: 15, height: 1.4, color: scheme.onSurface)),
          ],
          if (event.body.isNotEmpty) ...[
            const SizedBox(height: 8),
            BlockRenderer(blocks: event.body),
          ],
          if (_venue != null) ...[
            const SizedBox(height: 20),
            Card(
              child: InkWell(
                borderRadius: BorderRadius.circular(ArucadRadius.card),
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
                          borderRadius:
                              BorderRadius.circular(ArucadRadius.compact)),
                      child: const Icon(Icons.place_outlined,
                          color: ArucadColors.primary),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                              s
                                  .t('event_view_venue')
                                  .replaceAll('{name}', _venue!.name),
                              style: const TextStyle(fontWeight: FontWeight.w800)),
                          const SizedBox(height: 3),
                          Text(
                              '⭐ ${_venue!.rating} · ${campusDensityInfo(_venue).$2} · ${s.t('event_venue_sub')}',
                              style: TextStyle(
                                  color: scheme.onSurfaceVariant, fontSize: 12)),
                        ],
                      ),
                    ),
                    Icon(Icons.chevron_right, color: scheme.onSurfaceVariant),
                  ]),
                ),
              ),
            ),
          ],
          const SizedBox(height: 28),
          SizedBox(
            width: double.infinity,
            child: _joined
                ? OutlinedButton.icon(
                    onPressed: null,
                    icon: const Icon(Icons.check_circle,
                        color: ArucadColors.success),
                    label: Text(s.t('common_joined')),
                    style: OutlinedButton.styleFrom(
                        disabledForegroundColor: ArucadColors.success,
                        side: const BorderSide(color: ArucadColors.success)),
                  )
                : FilledButton(
                    onPressed: _join,
                    child: Text(s.t('common_join')),
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
          Icon(icon,
              size: 18,
              color: Theme.of(context).colorScheme.onSurfaceVariant),
          const SizedBox(width: 10),
          Expanded(
            child: RichText(
              text: TextSpan(
                style: TextStyle(
                    color: Theme.of(context).colorScheme.onSurface,
                    fontSize: 13.5),
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
