import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/clubs/club_detail_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

class ExploreEventChip extends StatelessWidget {
  final CampusEvent event;
  final String reason;
  final double width;
  final Color accent;
  final VoidCallback onTap;

  const ExploreEventChip({
    super.key,
    required this.event,
    required this.reason,
    required this.width,
    required this.accent,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final ink = scheme.onSurface;
    final fill = Color.alphaBlend(
      accent.withValues(alpha: accent == ArucadColors.yellow ? .32 : .16),
      scheme.surface,
    );
    return StampCardFrame(
      width: width,
      height: 122,
      fill: fill,
      stroke: accent,
      onTap: onTap,
      padding: const EdgeInsets.fromLTRB(16, 14, 14, 12),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(event.category,
              style: TextStyle(
                  color: ink, fontSize: 11, fontWeight: FontWeight.w800)),
          const SizedBox(height: 4),
          Text(event.title,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(fontWeight: FontWeight.w900, color: ink)),
          const SizedBox(height: 3),
          Text('${event.placeName} · ${event.time}',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(color: scheme.onSurfaceVariant, fontSize: 12)),
          const SizedBox(height: 3),
          Text(reason,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                  color: ink, fontSize: 10.5, fontWeight: FontWeight.w600)),
        ],
      ),
    );
  }
}

class ExploreClubTile extends StatelessWidget {
  final CampusClub club;
  final Color accent;
  final List<CampusEvent> events;
  final CampusRepository repository;

  const ExploreClubTile({
    super.key,
    required this.club,
    required this.accent,
    required this.repository,
    this.events = const [],
  });

  @override
  Widget build(BuildContext context) {
    return Card(
      color: ArucadColors.paper,
      elevation: 0,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => Navigator.of(context).push(MaterialPageRoute(
            builder: (_) => ClubDetailScreen(
                club: club, events: events, repository: repository))),
        hoverColor: Colors.transparent,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            children: [
              Container(
                width: 52,
                height: 52,
                decoration: BoxDecoration(
                  color: accent.withValues(alpha: .12),
                  borderRadius: BorderRadius.circular(14),
                ),
                child: Icon(Icons.groups_rounded, color: accent, size: 28),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(club.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                            fontWeight: FontWeight.w900, fontSize: 15)),
                    const SizedBox(height: 4),
                    Text(club.category,
                        style: TextStyle(
                            color: accent,
                            fontSize: 12,
                            fontWeight: FontWeight.w800)),
                    const SizedBox(height: 3),
                    Text(club.description,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                            color: ArucadColors.muted, fontSize: 12.5)),
                  ],
                ),
              ),
              Icon(Icons.chevron_right_rounded,
                  color: accent.withValues(alpha: .7)),
            ],
          ),
        ),
      ),
    );
  }
}

class ExploreFoodVenueTile extends StatefulWidget {
  final CampusFoodVenue venue;
  final Color accent;

  const ExploreFoodVenueTile({
    super.key,
    required this.venue,
    required this.accent,
  });

  @override
  State<ExploreFoodVenueTile> createState() => _ExploreFoodVenueTileState();
}

class _ExploreFoodVenueTileState extends State<ExploreFoodVenueTile> {
  late DateTime _day;

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    _day = DateTime(now.year, now.month, now.day);
  }

  bool get _isToday {
    final now = DateTime.now();
    return _day.year == now.year &&
        _day.month == now.month &&
        _day.day == now.day;
  }

  Future<void> _pickDay() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _day,
      firstDate: DateTime.now().subtract(const Duration(days: 30)),
      lastDate: DateTime.now().add(const Duration(days: 60)),
    );
    if (picked != null) {
      setState(() => _day = DateTime(picked.year, picked.month, picked.day));
    }
  }

  Future<void> _openMenuFile() async {
    final url = widget.venue.menuFileUrl;
    if (url == null) return;
    await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
  }

  @override
  Widget build(BuildContext context) {
    final venue = widget.venue;
    final menu = venue.menuForDay(_day);
    final dayLabel =
        _isToday ? 'Bugün' : '${_day.day}.${_day.month}.${_day.year}';
    final accent = widget.accent;
    return Card(
      color: ArucadColors.paper,
      elevation: 0,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      clipBehavior: Clip.antiAlias,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Row(children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                  color: accent.withValues(alpha: .12),
                  borderRadius: BorderRadius.circular(14)),
              child: Icon(Icons.restaurant_outlined, color: accent),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(venue.name,
                        style: const TextStyle(
                            fontWeight: FontWeight.w900,
                            color: ArucadColors.ink,
                            fontSize: 15)),
                    if (venue.hours != null)
                      Text(venue.hours!,
                          style: const TextStyle(
                              color: ArucadColors.muted, fontSize: 11.5)),
                  ]),
            ),
            TextButton.icon(
              onPressed: _pickDay,
              icon:
                  Icon(Icons.calendar_month_outlined, size: 16, color: accent),
              label: Text(dayLabel,
                  style: const TextStyle(
                      fontSize: 12.5, color: ArucadColors.ink)),
            ),
          ]),
          const SizedBox(height: 8),
          if (menu == null)
            Text('$dayLabel için menü henüz girilmedi.',
                style:
                    const TextStyle(color: ArucadColors.muted, fontSize: 12.5))
          else ...[
            Text(
                menu.items.isEmpty
                    ? 'Menü detayı girilmedi'
                    : menu.items.join(' · '),
                style: const TextStyle(fontSize: 12.5)),
            if (menu.price != null || menu.hours != null) ...[
              const SizedBox(height: 3),
              Text(
                  [
                    if (menu.price != null) menu.price!,
                    if (menu.hours != null) menu.hours!
                  ].join(' · '),
                  style: const TextStyle(
                      color: ArucadColors.muted, fontSize: 11.5)),
            ],
          ],
          // Typed menu text renders in place — a student should not have to
          // open a document to find out what is being served today.
          if ((venue.menuText ?? '').trim().isNotEmpty) ...[
            const SizedBox(height: 8),
            Text(venue.menuText!.trim(),
                style: const TextStyle(fontSize: 12.5, height: 1.45)),
          ],
          if (venue.menuFileUrl != null) ...[
            const SizedBox(height: 8),
            OutlinedButton.icon(
              onPressed: _openMenuFile,
              icon: const Icon(Icons.download_outlined, size: 16),
              label: const Text('Aylık Menüyü Aç / İndir',
                  style: TextStyle(fontSize: 12.5)),
            ),
          ],
        ]),
      ),
    );
  }
}

String exploreEventReason(CampusEvent event, CampusUser? me) {
  final interests = me?.interests ?? const <String>[];
  for (final interest in interests) {
    if (event.category.toLowerCase().contains(interest.toLowerCase()) ||
        interest.toLowerCase().contains(event.category.toLowerCase())) {
      return 'İlgi alanına uygun: $interest';
    }
  }
  return 'Bu hafta öne çıkan etkinlik';
}
