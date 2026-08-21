import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/home/event_detail_screen.dart';
import 'package:arucad_campus_prototype/features/home/event_join_sheet.dart';

/// Standard page size for every "show N, then load more" list in the app —
/// dumping everything into one screen doesn't scale as real data grows.
const kPageSize = 5;

/// A [ChoiceChip] with guaranteed contrast: a solid, unambiguous blue fill
/// with white text when selected — not a translucent tint whose exact
/// on-screen contrast depends on how the Material theme resolves an
/// unspecified label color. Use this instead of a bare `ChoiceChip`
/// anywhere selection state needs to be legible at a glance.
class SelectableChip extends StatelessWidget {
  final String label;
  final bool selected;
  final ValueChanged<bool> onSelected;

  const SelectableChip({
    super.key,
    required this.label,
    required this.selected,
    required this.onSelected,
  });

  @override
  Widget build(BuildContext context) => ChoiceChip(
        label: Text(label),
        selected: selected,
        onSelected: onSelected,
        showCheckmark: false,
        selectedColor: ArucadColors.primary,
        backgroundColor: ArucadColors.mist,
        labelStyle: TextStyle(
          fontWeight: FontWeight.w700,
          fontSize: 13,
          color: selected ? Colors.white : ArucadColors.ink,
        ),
      );
}

/// Shared "Daha fazla göster" control for paginated lists: a button while
/// there's more to load, a quiet "hepsi listelendi" note once there isn't.
class LoadMoreButton extends StatelessWidget {
  final int shown;
  final int total;
  final VoidCallback onTap;
  final String itemLabel;

  const LoadMoreButton({
    super.key,
    required this.shown,
    required this.total,
    required this.onTap,
    this.itemLabel = 'öğe',
  });

  @override
  Widget build(BuildContext context) {
    final remaining = total - shown;
    if (remaining <= 0) {
      return total == 0
          ? const SizedBox.shrink()
          : Center(
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 10),
                child: Text('Tüm $total $itemLabel listelendi',
                    style: const TextStyle(color: ArucadColors.muted, fontSize: 12)),
              ),
            );
    }
    return Center(
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 10),
        child: OutlinedButton.icon(
          onPressed: onTap,
          icon: const Icon(Icons.expand_more),
          label: Text('Daha fazla göster ($remaining)'),
        ),
      ),
    );
  }
}

class BrandMark extends StatelessWidget {
  final double height;
  const BrandMark({super.key, this.height = 30});

  @override
  Widget build(BuildContext context) =>
      Image.asset('assets/images/arucad_home_logo.png', height: height);
}

class SearchCard extends StatelessWidget {
  final VoidCallback onTap;
  const SearchCard({super.key, required this.onTap});

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(20),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 16),
          decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20), boxShadow: [
            BoxShadow(color: Colors.black.withAlpha((0.04 * 255).round()), blurRadius: 16, offset: const Offset(0, 5)),
          ]),
          child: Row(children: [
            const Icon(Icons.search, color: ArucadColors.primary),
            const SizedBox(width: 12),
            const Expanded(child: Text('Yer, etkinlik ara veya Ask ARUCAD\'a sor', style: TextStyle(fontSize: 15, color: ArucadColors.muted))),
            const Icon(Icons.arrow_forward_ios, size: 15, color: ArucadColors.muted),
          ]),
        ),
      );
}

class MiniStat extends StatelessWidget {
  final String label;
  final String value;
  final IconData icon;
  const MiniStat({super.key, required this.label, required this.value, required this.icon});

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(color: ArucadColors.mist, borderRadius: BorderRadius.circular(18)),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Container(
            width: 32,
            height: 32,
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
            child: Icon(icon, size: 18, color: ArucadColors.primary),
          ),
          const SizedBox(height: 10),
          Text(value, style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w900)),
          const SizedBox(height: 4),
          Text(label, style: const TextStyle(fontSize: 11, color: ArucadColors.muted)),
        ]),
      );
}

class SectionHeader extends StatelessWidget {
  final String title;
  final String action;
  final VoidCallback onTap;
  const SectionHeader({super.key, required this.title, required this.action, required this.onTap});

  @override
  Widget build(BuildContext context) => Row(children: [
        Expanded(child: Text(title, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 18))),
        TextButton(onPressed: onTap, child: Text(action)),
      ]);
}

class TrendTile extends StatelessWidget {
  final IconData icon;
  final String title;
  final String subtitle;
  final String trailing;
  final VoidCallback? onTap;
  final Color? accentColor;
  const TrendTile({
    super.key,
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.trailing,
    this.onTap,
    this.accentColor,
  });

  @override
  Widget build(BuildContext context) {
    final accent = accentColor ?? ArucadColors.slateBlue;
    return SizedBox(
        width: 220,
        child: Card(
          color: accent.withValues(alpha: .06),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(22)),
          child: ListTile(
            onTap: onTap,
            leading: CircleAvatar(backgroundColor: accent.withValues(alpha: .18), child: Icon(icon, color: accent)),
            title: Text(title, style: const TextStyle(fontWeight: FontWeight.w900)),
            subtitle: Text(subtitle),
            trailing: Container(
              padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
              decoration: BoxDecoration(color: accent.withValues(alpha: .18), borderRadius: BorderRadius.circular(14)),
              child: Text(trailing, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 12)),
            ),
          ),
        ),
      );
  }
}

class JourneyCard extends StatelessWidget {
  final CampusUser user;
  final VoidCallback onTap;
  const JourneyCard({super.key, required this.user, required this.onTap});

  @override
  Widget build(BuildContext context) => Card(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
        color: ArucadColors.primary,
        child: Padding(
          padding: const EdgeInsets.all(22),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              const Expanded(child: Text('Your Journey', style: TextStyle(color: Colors.white, fontWeight: FontWeight.w800, fontSize: 16))),
              Text('Level ${user.level}', style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w900)),
            ]),
            const SizedBox(height: 10),
            Text('${user.xp} XP', style: const TextStyle(color: Colors.white, fontSize: 34, fontWeight: FontWeight.w900)),
            const SizedBox(height: 14),
            const LinearProgressIndicator(value: .92, backgroundColor: Colors.white24, color: Colors.white),
            const SizedBox(height: 12),
            const Text('3 place left to complete Campus Explorer', style: TextStyle(color: Colors.white70)),
            const SizedBox(height: 14),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton(
                style: FilledButton.styleFrom(backgroundColor: Colors.white, foregroundColor: ArucadColors.primary, shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16))),
                onPressed: onTap,
                child: const Text('Continue Journey', style: TextStyle(fontWeight: FontWeight.w900)),
              ),
            ),
          ]),
        ),
      );
}

class EventCard extends StatelessWidget {
  final CampusEvent event;
  final CampusRepository repository;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;
  const EventCard({
    super.key,
    required this.event,
    required this.repository,
    required this.mapProvider,
    required this.analyticsTracker,
  });

  void _openDetail(BuildContext context) {
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => EventDetailScreen(
            event: event,
            repository: repository,
            mapProvider: mapProvider,
            analyticsTracker: analyticsTracker)));
  }

  @override
  Widget build(BuildContext context) {
    // Real decluttering pass (docs/EKSIKLER.md aktivite §6/§7): exactly
    // what a browsing card needs — name, date+time, location, category,
    // a one-line description, participation, and the one action that
    // matters — everything else (faculty, purpose, requirements, poster,
    // assigned staff...) stays in EventDetailScreen, never repeated here.
    final accent = categoryAccent(event.category);
    final date = event.eventDate;
    final dateLabel = date == null
        ? event.time
        : '${date.day.toString().padLeft(2, '0')}.${date.month.toString().padLeft(2, '0')} · ${event.time}';
    return Card(
        color: accent.withValues(alpha: .06),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(22)),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: () => _openDetail(context),
          child: Padding(
          padding: const EdgeInsets.all(16),
          child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Container(
              width: 52,
              height: 52,
              decoration: BoxDecoration(color: accent.withValues(alpha: .22), borderRadius: BorderRadius.circular(16)),
              child: Center(child: Text(event.time, textAlign: TextAlign.center, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 12, color: ArucadColors.ink))),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                Row(children: [
                  Expanded(
                      child: Text(event.title,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(fontWeight: FontWeight.w900))),
                  const SizedBox(width: 6),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 2),
                    decoration: BoxDecoration(
                        color: accent.withValues(alpha: .18), borderRadius: BorderRadius.circular(999)),
                    child: Text(event.category,
                        style: TextStyle(fontSize: 10, fontWeight: FontWeight.w800, color: accent)),
                  ),
                ]),
                const SizedBox(height: 4),
                Text('$dateLabel · ${event.placeName}',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5)),
                if (event.description.isNotEmpty) ...[
                  const SizedBox(height: 3),
                  Text(event.description,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontSize: 12.5)),
                ],
                const SizedBox(height: 3),
                Text('${event.attendees} katılımcı', style: const TextStyle(color: ArucadColors.muted, fontSize: 11.5)),
              ]),
            ),
            const SizedBox(width: 8),
            FilledButton(
              style: FilledButton.styleFrom(
                  backgroundColor: accent,
                  shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16))),
              onPressed: () => showEventJoinSheet(context, repository, event),
              child: const Text('Katıl'),
            ),
          ]),
          ),
        ),
      );
  }
}

/// Warm→cool color coding for a place's live crowd density, shared between
/// the map's info sheet and Explore's list/cards so "busy" looks the same
/// everywhere in the app.
(Color, String) campusDensityInfo(CampusPlace? place) {
  final raw = place?.density.toLowerCase() ?? '';
  if (raw.contains('busy') || raw.contains('high')) {
    return (ArucadColors.danger, 'Yoğun');
  }
  if (raw.contains('moderate')) return (ArucadColors.warning, 'Orta yoğunluk');
  if (raw.contains('quiet')) return (ArucadColors.success, 'Sakin');
  return (ArucadColors.blue, 'Bilinmiyor');
}

/// The six-color accent set used for card/section backgrounds — see
/// `ArucadColors`' own doc comment for why these exist alongside (not
/// instead of) the brand blue and the status colors.
const _categoryAccents = [
  ArucadColors.slateBlue,
  ArucadColors.terracotta,
  ArucadColors.sage,
  ArucadColors.dustyRose,
  ArucadColors.honey,
  ArucadColors.mistLilac,
];

/// Deterministic category → accent color (same idea as [campusOnlineCount]:
/// stable per name, not random per rebuild), so "Studio" always gets the
/// same card accent everywhere it appears instead of a lookup table that
/// needs a new entry for every category anyone ever types in.
Color categoryAccent(String category) =>
    _categoryAccents[category.hashCode.abs() % _categoryAccents.length];

/// Deterministic "how many people are here right now" estimate — there's no
/// real presence backend, so this is derived from the place name (stable
/// per place, not random on every rebuild) rather than invented per screen.
/// Shared by the live map's info sheet and Explore so the same place always
/// shows the same number everywhere in the app.
int campusOnlineCount(String name) => 18 + (name.hashCode.abs() % 42);

/// Same idea as [campusOnlineCount], for a club's member count — there's no
/// real membership roster, so this is a stable per-club estimate rather
/// than an invented-per-screen number.
int campusClubMemberEstimate(String name) => 22 + (name.hashCode.abs() % 60);

/// Events happening at [placeName] right now, matched loosely against the
/// event's own place name (same matching rule used by the live map).
List<CampusEvent> eventsAtPlace(List<CampusEvent> events, String placeName) {
  final target = placeName.toLowerCase();
  return events.where((e) {
    final candidate = e.placeName.toLowerCase();
    return candidate == target ||
        candidate.contains(target) ||
        target.contains(candidate);
  }).toList();
}

class PlaceCard extends StatelessWidget {
  final CampusPlace place;
  final VoidCallback onOpen;
  final List<CampusEvent> events;
  final int checkInCount;
  final String? distanceLabel;

  const PlaceCard({
    super.key,
    required this.place,
    required this.onOpen,
    this.events = const [],
    this.checkInCount = 0,
    this.distanceLabel,
  });

  @override
  Widget build(BuildContext context) {
    final (densityColor, densityLabel) = campusDensityInfo(place);
    final activeCount = campusOnlineCount(place.name);
    final hasEventNow = eventsAtPlace(events, place.name).isNotEmpty;
    final accent = categoryAccent(place.category);
    return Card(
      color: accent.withValues(alpha: .06),
      child: InkWell(
        onTap: onOpen,
        borderRadius: BorderRadius.circular(22),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Container(
                width: 56,
                height: 56,
                decoration: BoxDecoration(
                    color: densityColor.withValues(alpha: .14),
                    borderRadius: BorderRadius.circular(16)),
                child: Icon(Icons.place_outlined, color: densityColor),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Text(place.name,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
                  const SizedBox(height: 4),
                  Text('${place.category} · ${distanceLabel ?? place.distance}',
                      maxLines: 1, overflow: TextOverflow.ellipsis),
                  const SizedBox(height: 4),
                  Row(children: [
                    Container(
                      width: 8,
                      height: 8,
                      decoration:
                          BoxDecoration(color: densityColor, shape: BoxShape.circle),
                    ),
                    const SizedBox(width: 6),
                    Text(densityLabel,
                        style: TextStyle(
                            color: densityColor,
                            fontSize: 12,
                            fontWeight: FontWeight.w800)),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(place.street,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                              color: ArucadColors.muted, fontSize: 12)),
                    ),
                  ]),
                ]),
              ),
              const Icon(Icons.chevron_right),
            ]),
            const SizedBox(height: 10),
            Wrap(spacing: 10, runSpacing: 6, children: [
              _InfoBadge(icon: Icons.people_alt_outlined, label: '$activeCount aktif'),
              if (hasEventNow)
                const _InfoBadge(
                    icon: Icons.event_available_outlined,
                    label: 'Etkinlik var',
                    color: ArucadColors.primary),
              _InfoBadge(
                  icon: Icons.photo_library_outlined,
                  label: place.photos > 0 ? '${place.photos} foto' : 'Foto yok'),
              if (checkInCount > 0)
                _InfoBadge(
                    icon: Icons.verified_outlined, label: '$checkInCount check-in'),
            ]),
          ]),
        ),
      ),
    );
  }
}

class _InfoBadge extends StatelessWidget {
  final IconData icon;
  final String label;
  final Color? color;
  const _InfoBadge({required this.icon, required this.label, this.color});

  @override
  Widget build(BuildContext context) {
    final c = color ?? ArucadColors.muted;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
      decoration: BoxDecoration(
          color: c.withValues(alpha: .1), borderRadius: BorderRadius.circular(999)),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(icon, size: 13, color: c),
        const SizedBox(width: 4),
        Text(label, style: TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: c)),
      ]),
    );
  }
}

class PillChip extends StatelessWidget {
  final IconData icon;
  final String label;
  const PillChip({super.key, required this.icon, required this.label});

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 9),
        decoration: BoxDecoration(color: ArucadColors.mist, borderRadius: BorderRadius.circular(999)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Icon(icon, size: 16),
          const SizedBox(width: 5),
          Text(label, style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w800)),
        ]),
      );
}

