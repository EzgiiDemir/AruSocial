import 'dart:math' as math;

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/models/staff_application.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/core/theme/campus_density.dart';
import 'package:arucad_campus_prototype/core/utils/relative_time.dart';
import 'package:arucad_campus_prototype/features/home/event_detail_screen.dart';
import 'package:arucad_campus_prototype/features/home/event_join_sheet.dart';

export 'package:arucad_campus_prototype/core/theme/campus_density.dart';

/// Standard page size for every "show N, then load more" list in the app —
/// dumping everything into one screen doesn't scale as real data grows.
const kPageSize = 5;

/// Identical, full-width page chrome for the application's primary sections.
/// It stays on one row at phone widths, clips long titles safely, and keeps
/// the tappable controls at least 44 px high.
class CampusPageHeader extends StatelessWidget {
  final String title;
  final Widget? leading;
  final List<Widget> actions;
  final bool includeTopSafeArea;

  const CampusPageHeader({
    super.key,
    required this.title,
    this.leading,
    this.actions = const [],
    this.includeTopSafeArea = true,
  });

  static const double height = 56;

  @override
  Widget build(BuildContext context) {
    final compact = MediaQuery.sizeOf(context).width < 380;
    final row = SizedBox(
      width: double.infinity,
      height: height,
      child: Padding(
        padding: EdgeInsets.symmetric(horizontal: compact ? 10 : 12),
        child: Row(
          children: [
            if (leading != null) ...[
              SizedBox(
                height: 44,
                child: Center(child: leading),
              ),
              SizedBox(width: compact ? 4 : 8),
            ],
            Expanded(
              child: Text(
                title,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  fontFamily: ArucadFonts.oswald,
                  color: ArucadColors.ink,
                  fontSize: compact ? 20 : 22,
                  fontWeight: FontWeight.w700,
                  height: 1.1,
                ),
              ),
            ),
            if (actions.isNotEmpty) ...[
              SizedBox(width: compact ? 4 : 8),
              ...actions,
            ],
          ],
        ),
      ),
    );

    final content = IconTheme(
      data: const IconThemeData(color: ArucadColors.ink),
      child: DefaultTextStyle.merge(
        style: const TextStyle(color: ArucadColors.ink),
        child: row,
      ),
    );

    return ColoredBox(
      color: ArucadColors.paper,
      child: includeTopSafeArea
          ? SafeArea(bottom: false, child: content)
          : content,
    );
  }
}

/// A [ChoiceChip] with guaranteed contrast: a solid brand fill
/// with white (or ink, on yellow) text when selected.
class SelectableChip extends StatelessWidget {
  final String label;
  final bool selected;
  final ValueChanged<bool> onSelected;
  final Color selectedColor;

  const SelectableChip({
    super.key,
    required this.label,
    required this.selected,
    required this.onSelected,
    this.selectedColor = ArucadColors.primary,
  });

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return ChoiceChip(
      label: Text(label),
      selected: selected,
      onSelected: onSelected,
      showCheckmark: false,
      selectedColor: selectedColor,
      backgroundColor: scheme.surfaceContainerHighest,
      labelStyle: TextStyle(
        fontWeight: FontWeight.w700,
        fontSize: 13,
        color: selected ? Colors.white : scheme.onSurface,
      ),
    );
  }
}

/// Story/post composer: Herkes · Arkadaşlarım (mutual follows) · Sadece ben.
class AudienceChips extends StatelessWidget {
  final PostVisibility value;
  final ValueChanged<PostVisibility> onChanged;

  const AudienceChips({
    super.key,
    required this.value,
    required this.onChanged,
  });

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    return Wrap(
      spacing: 8,
      runSpacing: 8,
      children: [
        SelectableChip(
          label: strings.t('social_visibility_everyone'),
          selected: value == PostVisibility.everyone,
          onSelected: (_) => onChanged(PostVisibility.everyone),
        ),
        SelectableChip(
          label: strings.t('social_visibility_friends'),
          selected: value == PostVisibility.friends,
          selectedColor: ArucadColors.blue,
          onSelected: (_) => onChanged(PostVisibility.friends),
        ),
        SelectableChip(
          label: strings.t('social_visibility_only_me'),
          selected: value == PostVisibility.onlyMe,
          onSelected: (_) => onChanged(PostVisibility.onlyMe),
        ),
      ],
    );
  }
}

/// Shared "Daha fazla göster" control for paginated lists: a button while
/// there's more to load, a quiet "hepsi listelendi" note once there isn't.
class LoadMoreButton extends StatelessWidget {
  final int shown;
  final int total;
  final VoidCallback onTap;
  final String itemLabel;
  final bool showCompleteLabel;

  const LoadMoreButton({
    super.key,
    required this.shown,
    required this.total,
    required this.onTap,
    this.itemLabel = 'öğe',
    this.showCompleteLabel = true,
  });

  @override
  Widget build(BuildContext context) {
    final remaining = total - shown;
    if (remaining <= 0) {
      return total == 0 || !showCompleteLabel
          ? const SizedBox.shrink()
          : Center(
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 10),
                child: Text('Tüm $total $itemLabel listelendi >',
                    style: const TextStyle(
                        color: ArucadColors.primary,
                        fontWeight: FontWeight.w700,
                        fontSize: 12.5)),
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
  final bool showWordmark;
  const BrandMark({
    super.key,
    this.height = 30,
    this.showWordmark = true,
  });

  @override
  Widget build(BuildContext context) {
    // ARUVERSE is the product identity. Keep the regular/light-mode emblem
    // in every theme; only the surrounding header surface changes.
    return Align(
      alignment: Alignment.centerLeft,
      child: FittedBox(
        fit: BoxFit.scaleDown,
        alignment: Alignment.centerLeft,
        child: Row(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            Image.asset(
              'assets/images/aruverse_emblem.png',
              height: height,
              filterQuality: FilterQuality.high,
            ),
            if (showWordmark) ...[
              SizedBox(width: height * 0.28),
              Text(
                'ARUVERSE',
                style: TextStyle(
                  fontSize: height * 0.62,
                  fontWeight: FontWeight.w900,
                  letterSpacing: 0.6,
                  height: 1,
                  color: ArucadColors.ink,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class SearchCard extends StatelessWidget {
  final VoidCallback onTap;
  const SearchCard({super.key, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(ArucadRadius.card),
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 18, vertical: 16),
        decoration: BoxDecoration(
            color: scheme.surface,
            borderRadius: BorderRadius.circular(ArucadRadius.card),
            boxShadow: [
              BoxShadow(
                  color: Colors.black.withAlpha((0.04 * 255).round()),
                  blurRadius: 16,
                  offset: const Offset(0, 5)),
            ]),
        child: Row(children: [
          const Icon(Icons.search, color: ArucadColors.primary),
          const SizedBox(width: 12),
          Expanded(
              child: Text(AppLocale.of(context).t('home_search_hint'),
                  style:
                      TextStyle(fontSize: 15, color: scheme.onSurfaceVariant))),
          Icon(Icons.arrow_forward_ios,
              size: 15, color: scheme.onSurfaceVariant),
        ]),
      ),
    );
  }
}

class MiniStat extends StatelessWidget {
  final String label;
  final String value;
  final IconData icon;
  const MiniStat(
      {super.key,
      required this.label,
      required this.value,
      required this.icon});

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
          color: scheme.surfaceContainerHighest,
          borderRadius: BorderRadius.circular(18)),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Container(
          width: 32,
          height: 32,
          decoration: BoxDecoration(
              color: scheme.surface, borderRadius: BorderRadius.circular(12)),
          child: Icon(icon, size: 18, color: ArucadColors.primary),
        ),
        const SizedBox(height: 10),
        Text(value,
            style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w900)),
        const SizedBox(height: 4),
        Text(label,
            style: TextStyle(fontSize: 11, color: scheme.onSurfaceVariant)),
      ]),
    );
  }
}

class SectionHeader extends StatelessWidget {
  final String title;
  final String action;
  final VoidCallback onTap;
  final IconData? actionIcon;
  final Color? actionColor;
  const SectionHeader({
    super.key,
    required this.title,
    required this.action,
    required this.onTap,
    this.actionIcon,
    this.actionColor,
  });

  @override
  Widget build(BuildContext context) {
    // The action reads as a link, not a button: on web the default
    // TextButton overlay painted a grey slab behind "Tümü" on hover,
    // which looked like a stray box floating over the section.
    final style = TextButton.styleFrom(
      foregroundColor: actionColor,
    ).copyWith(overlayColor: const WidgetStatePropertyAll(Colors.transparent));

    return Row(children: [
      Expanded(
          child: Text(title,
              style:
                  const TextStyle(fontWeight: FontWeight.w900, fontSize: 18))),
      if (actionIcon == null)
        TextButton(style: style, onPressed: onTap, child: Text(action))
      else
        TextButton.icon(
          style: style,
          onPressed: onTap,
          icon: Icon(actionIcon, size: 16),
          label: Text(action),
        ),
    ]);
  }
}

class TrendTile extends StatelessWidget {
  final IconData icon;
  final String title;
  final String subtitle;
  final String trailing;
  final VoidCallback? onTap;
  final Color? accentColor;
  final Widget? leading;

  /// When true, card background stays neutral — only the leading icon keeps
  /// the accent colour; title/trailing use theme body ink (Home "Sana Özel").
  final bool accentIconTextOnly;
  const TrendTile({
    super.key,
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.trailing,
    this.onTap,
    this.accentColor,
    this.leading,
    this.accentIconTextOnly = false,
  });

  @override
  Widget build(BuildContext context) {
    final accent = accentColor ?? ArucadColors.primary;
    final ink = Theme.of(context).colorScheme.onSurface;
    return SizedBox(
      width: 220,
      child: Card(
        color: accentIconTextOnly
            ? Theme.of(context).colorScheme.surface
            : accent.withValues(alpha: .06),
        shape: RoundedRectangleBorder(
          borderRadius: BorderRadius.circular(22),
          side: accentIconTextOnly
              ? const BorderSide(color: ArucadColors.mist)
              : BorderSide.none,
        ),
        child: ListTile(
          onTap: onTap,
          hoverColor: Colors.transparent,
          leading: leading ??
              CircleAvatar(
                backgroundColor: accentIconTextOnly
                    ? Colors.transparent
                    : accent.withValues(alpha: .18),
                child: Icon(icon, color: accent),
              ),
          title: Text(title,
              style: TextStyle(
                  fontWeight: FontWeight.w900,
                  color: accentIconTextOnly ? ink : null)),
          subtitle: Text(subtitle),
          trailing: accentIconTextOnly
              ? Text(trailing,
                  style: TextStyle(
                      fontWeight: FontWeight.w800, fontSize: 12, color: ink))
              : Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                  decoration: BoxDecoration(
                      color: accent.withValues(alpha: .18),
                      borderRadius: BorderRadius.circular(14)),
                  child: Text(trailing,
                      style: const TextStyle(
                          fontWeight: FontWeight.w800, fontSize: 12)),
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
          child:
              Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              const Expanded(
                  child: Text('Your Journey',
                      style: TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.w800,
                          fontSize: 16))),
              Text('Level ${user.level}',
                  style: const TextStyle(
                      color: Colors.white, fontWeight: FontWeight.w900)),
            ]),
            const SizedBox(height: 10),
            Text('${user.xp} XP',
                style: const TextStyle(
                    color: Colors.white,
                    fontSize: 34,
                    fontWeight: FontWeight.w900)),
            const SizedBox(height: 14),
            const LinearProgressIndicator(
                value: .92,
                backgroundColor: Colors.white24,
                color: Colors.white),
            const SizedBox(height: 12),
            const Text('3 place left to complete Campus Explorer',
                style: TextStyle(color: Colors.white70)),
            const SizedBox(height: 14),
            Align(
              alignment: Alignment.centerRight,
              child: FilledButton(
                style: FilledButton.styleFrom(
                    backgroundColor: Colors.white,
                    foregroundColor: ArucadColors.primary,
                    shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(16))),
                onPressed: onTap,
                child: const Text('Continue Journey',
                    style: TextStyle(fontWeight: FontWeight.w900)),
              ),
            ),
          ]),
        ),
      );
}

class EventCard extends StatefulWidget {
  final CampusEvent event;
  final CampusRepository repository;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;
  final Color? accentColor;
  const EventCard({
    super.key,
    required this.event,
    required this.repository,
    required this.mapProvider,
    required this.analyticsTracker,
    this.accentColor,
  });

  @override
  State<EventCard> createState() => _EventCardState();
}

class _EventCardState extends State<EventCard> {
  bool _joined = false;

  @override
  void initState() {
    super.initState();
    _resolveJoined();
  }

  // Same real check EventDetailScreen uses — a real `eventJoin` activity
  // row, not a guess — so a list card already reflects a join made
  // earlier, not just one just performed in this session.
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

  void _openDetail(BuildContext context) {
    Navigator.of(context).push(MaterialPageRoute(
        builder: (_) => EventDetailScreen(
            event: widget.event,
            repository: widget.repository,
            mapProvider: widget.mapProvider,
            analyticsTracker: widget.analyticsTracker)));
  }

  Future<void> _join() async {
    final result =
        await showEventJoinSheet(context, widget.repository, widget.event);
    if (!mounted || result == null) return;
    setState(() => _joined = true);
  }

  @override
  Widget build(BuildContext context) {
    final event = widget.event;
    final accent = widget.accentColor ?? categoryAccent(event.category);
    // The time badge and the join button are solid fills, so they use the
    // darkened variant that white text can sit on. `accent` itself stays
    // the original hue for outlines and text-on-white.
    // Keep the approved ARUCAD yellow visibly yellow. Darkening it until
    // white text passes contrast turned the event control brown; yellow is
    // already accessible with the ink foreground returned by onAccent.
    final fill = accent == ArucadColors.yellow
        ? ArucadColors.yellow
        : accentFill(accent);
    final onFill = onAccent(fill);
    return Card(
      color: ArucadColors.paper,
      elevation: 0,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: () => _openDetail(context),
        hoverColor: Colors.transparent,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(children: [
            Container(
              width: 52,
              height: 52,
              decoration: BoxDecoration(
                  color: fill, borderRadius: BorderRadius.circular(14)),
              child: Center(
                  child: Text(event.time,
                      textAlign: TextAlign.center,
                      style: TextStyle(
                          fontWeight: FontWeight.w900,
                          fontSize: 12,
                          color: onFill))),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(event.title,
                        style: const TextStyle(fontWeight: FontWeight.w900)),
                    const SizedBox(height: 4),
                    Text('${event.placeName} · ${event.attendees} going',
                        style: TextStyle(
                            color: Theme.of(context)
                                .colorScheme
                                .onSurfaceVariant)),
                  ]),
            ),
            _joined
                ? OutlinedButton.icon(
                    onPressed: null,
                    icon: Icon(Icons.check, size: 16, color: accent),
                    label: Text('Katıldın', style: TextStyle(color: accent)),
                    style: OutlinedButton.styleFrom(
                        foregroundColor: accent,
                        disabledForegroundColor: accent,
                        side: BorderSide(color: accent),
                        shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(16))),
                  )
                : FilledButton(
                    style: FilledButton.styleFrom(
                        backgroundColor: fill,
                        foregroundColor: onFill,
                        shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(16))),
                    onPressed: _join,
                    child: const Text('Katıl'),
                  ),
          ]),
        ),
      ),
    );
  }
}

/// Full brand cycle: red → blue → yellow → green.
const _brandCycle = [
  ArucadColors.red,
  ArucadColors.blue,
  ArucadColors.yellow,
  ArucadColors.campusGreen,
];

/// Social rail order: Home = red → blue → yellow → green.
const _socialNavCycle = [
  ArucadColors.red,
  ArucadColors.blue,
  ArucadColors.yellow,
  ArucadColors.campusGreen,
];

/// Deterministic category → accent color (same idea as [campusOnlineCount]:
/// stable per name, not random per rebuild), so "Studio" always gets the
/// same card accent everywhere it appears instead of a lookup table that
/// needs a new entry for every category anyone ever types in.
Color categoryAccent(String category) =>
    _brandCycle[category.hashCode.abs() % _brandCycle.length];

/// Sequential accent for list items — cycles through all four brand colours.
Color brandAccentAt(int index) => _brandCycle[index.abs() % _brandCycle.length];

/// Social sidebar icon colours (red, blue, yellow, green).
Color socialNavAccentAt(int index) =>
    _socialNavCycle[index.abs() % _socialNavCycle.length];

class PlaceLineArt {
  final String asset;
  final Color color;
  const PlaceLineArt(this.asset, this.color);
}

/// Approved ARUCAD line-art marks for the campus catalogue.  Matching by
/// display name also covers the legacy check-in records that point to the
/// canonical places (for example the alternate The Garden record).
PlaceLineArt? placeLineArt(String name) {
  final n = name.toLowerCase();
  if (n.contains('ana kampüs') ||
      n.contains('ana kampus') ||
      n.contains('main campus entrance')) {
    return const PlaceLineArt(
        'assets/images/places/01-main-campus-entrance.png',
        ArucadColors.campusGreen);
  }
  if (n.contains('rodin') && !n.contains('gallery')) {
    return const PlaceLineArt(
        'assets/images/places/02-rodin.png', ArucadColors.campusGreen);
  }
  if (n.contains('falling man')) {
    return const PlaceLineArt(
        'assets/images/places/03-falling-man.png', ArucadColors.campusGreen);
  }
  if (n.contains('titan')) {
    return const PlaceLineArt(
        'assets/images/places/04-titan.png', ArucadColors.campusGreen);
  }
  if (n == 'eve' || n.contains(' eve')) {
    return const PlaceLineArt(
        'assets/images/places/05-eve.png', ArucadColors.campusGreen);
  }
  if (n.contains('daniele')) {
    return const PlaceLineArt(
        'assets/images/places/06-daniele.png', ArucadColors.campusGreen);
  }
  if (n.contains('eternal spring')) {
    return const PlaceLineArt(
        'assets/images/places/07-eternal-spring.png', ArucadColors.campusGreen);
  }
  if (n.contains('meditation')) {
    return const PlaceLineArt(
        'assets/images/places/08-meditation.png', ArucadColors.campusGreen);
  }
  if (n.contains('minotaur')) {
    return const PlaceLineArt(
        'assets/images/places/09-minotaur.png', ArucadColors.campusGreen);
  }
  if (n.contains('eternal idol')) {
    return const PlaceLineArt(
        'assets/images/places/10-eternal-idol.png', ArucadColors.campusGreen);
  }
  if (n.contains('kiss')) {
    return const PlaceLineArt(
        'assets/images/places/11-the-kiss.png', ArucadColors.orange);
  }
  if (n.contains('garden')) {
    return const PlaceLineArt(
        'assets/images/places/12-the-garden.png', ArucadColors.lavender);
  }
  if (n.contains('carpentry')) {
    return const PlaceLineArt('assets/images/places/13-carpentry-studio.png',
        ArucadColors.campusGreen);
  }
  if (n.contains('arkin rodin')) {
    return const PlaceLineArt(
        'assets/images/places/14-arkin-rodin-collection-gallery.png',
    ArucadColors.campusGreen);
  }
  if (n.contains('dormitory')) {
    return const PlaceLineArt('assets/images/places/15-arucad-dormitory.png',
        ArucadColors.campusGreen);
  }
  if (n.contains('bandabuliya')) {
    return const PlaceLineArt(
        'assets/images/places/16-nicosia-bandabuliya-campus.png',
    ArucadColors.campusGreen);
  }
  if (n.contains('art space')) {
    return const PlaceLineArt('assets/images/places/17-arucad-art-space.png',
        ArucadColors.campusGreen);
  }
  if (n.contains('age of bronze')) {
    return const PlaceLineArt(
        'assets/images/places/18-age-of-bronze.png', ArucadColors.campusGreen);
  }
  if (n.contains('art rooms')) {
    return const PlaceLineArt(
        'assets/images/places/19-art-rooms.png', ArucadColors.campusGreen);
  }
  if (n.contains('iris')) {
    return const PlaceLineArt(
        'assets/images/places/20-iris-atelier-building.png',
    ArucadColors.campusGreen);
  }
  if (n.contains('workshops')) {
    return const PlaceLineArt('assets/images/places/21-arucad-workshops.png',
        ArucadColors.campusGreen);
  }
  return null;
}

class PlaceLineArtIcon extends StatelessWidget {
  final String placeName;
  final Color color;
  final double size;

  const PlaceLineArtIcon({
    super.key,
    required this.placeName,
    required this.color,
    this.size = 40,
  });

  @override
  Widget build(BuildContext context) {
    final art = placeLineArt(placeName);
    if (art == null) {
      return Icon(Icons.place_outlined, color: color, size: size * 0.7);
    }
    return ColorFiltered(
      colorFilter: ColorFilter.mode(color, BlendMode.srcIn),
      child: Image.asset(
        art.asset,
        width: size,
        height: size,
        fit: BoxFit.contain,
        filterQuality: FilterQuality.medium,
      ),
    );
  }
}

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
  final Color? accentColor;

  const PlaceCard({
    super.key,
    required this.place,
    required this.onOpen,
    this.events = const [],
    this.checkInCount = 0,
    this.distanceLabel,
    this.accentColor,
  });

  @override
  Widget build(BuildContext context) {
    final (densityColor, densityLabel) = campusDensityInfo(place);
    final bodyInk = Theme.of(context).colorScheme.onSurface;
    final accent = accentColor;
    final activeCount = campusPresenceCount(place);
    final hasEventNow = eventsAtPlace(events, place.name).isNotEmpty;
    return Card(
      color: ArucadColors.paper,
      elevation: 0,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(18)),
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        onTap: onOpen,
        borderRadius: BorderRadius.circular(18),
        hoverColor: Colors.transparent,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child:
              Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Row(children: [
              Container(
                width: 52,
                height: 52,
                decoration: BoxDecoration(
                    color: (accent ?? densityColor).withValues(alpha: .12),
                    borderRadius: BorderRadius.circular(14)),
                child: Center(
                  child: PlaceLineArtIcon(
                    placeName: place.name,
                    color: accent ?? densityColor,
                    size: 36,
                  ),
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(place.name,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                              fontWeight: FontWeight.w900,
                              fontSize: 15,
                              color: bodyInk)),
                      const SizedBox(height: 4),
                      Text(
                          '${place.category} · ${distanceLabel ?? place.distance}',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: const TextStyle(
                              color: ArucadColors.muted, fontSize: 13)),
                      const SizedBox(height: 4),
                      Row(children: [
                        Container(
                          width: 8,
                          height: 8,
                          decoration: BoxDecoration(
                              color: densityColor, shape: BoxShape.circle),
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
              Icon(Icons.chevron_right_rounded,
                  color: (accent ?? ArucadColors.muted).withValues(alpha: .8)),
            ]),
            const SizedBox(height: 10),
            Wrap(spacing: 10, runSpacing: 6, children: [
              _InfoBadge(
                  icon: Icons.people_alt_outlined, label: '$activeCount aktif'),
              if (hasEventNow)
                const _InfoBadge(
                    icon: Icons.event_available_outlined,
                    label: 'Etkinlik var',
                    color: ArucadColors.primary),
              _InfoBadge(
                  icon: Icons.photo_library_outlined,
                  label:
                      place.photos > 0 ? '${place.photos} foto' : 'Foto yok'),
              if (checkInCount > 0)
                _InfoBadge(
                    icon: Icons.verified_outlined,
                    label: '$checkInCount check-in'),
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
    final c = color ?? Theme.of(context).colorScheme.onSurfaceVariant;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
      decoration: BoxDecoration(
          color: c.withValues(alpha: .1),
          borderRadius: BorderRadius.circular(999)),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(icon, size: 13, color: c),
        const SizedBox(width: 4),
        Text(label,
            style:
                TextStyle(fontSize: 11, fontWeight: FontWeight.w700, color: c)),
      ]),
    );
  }
}

class PillChip extends StatelessWidget {
  final IconData icon;
  final String label;
  final Color? color;
  const PillChip(
      {super.key, required this.icon, required this.label, this.color});

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final ink = color == null ? scheme.onSurface : densityForeground(color!);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 9),
      decoration: BoxDecoration(
        color: color == null
            ? scheme.surfaceContainerHighest
            : color!
                .withValues(alpha: color == ArucadColors.yellow ? .28 : .14),
        borderRadius: BorderRadius.circular(999),
        border: color == null ? null : Border.all(color: color!),
      ),
      child: Row(mainAxisSize: MainAxisSize.min, children: [
        Icon(icon, size: 16, color: ink),
        const SizedBox(width: 5),
        Text(label,
            style: TextStyle(
                fontSize: 12, fontWeight: FontWeight.w800, color: ink)),
      ]),
    );
  }
}

/// Real EmailLog-backed "was the notification email actually sent?" status
/// for one application — shown in both Admin's and Trainer's Applications
/// tabs so a reviewer can see whether the student really got the detail
/// form link / decision email, not just that the app "should have" sent
/// one. See `ParticipationApplication.emailSent`/`lastEmailStatus`
/// (backend: `EmailLog.application_id`).
class EmailStatusRow extends StatelessWidget {
  final ParticipationApplication app;
  const EmailStatusRow({super.key, required this.app});

  @override
  Widget build(BuildContext context) {
    final (icon, color, label) = switch (app.lastEmailStatus) {
      'sent' => (
          Icons.mark_email_read_outlined,
          ArucadColors.success,
          'E-posta gönderildi'
        ),
      'failed' => (
          Icons.error_outline,
          ArucadColors.danger,
          'E-posta gönderilemedi'
        ),
      _ => (
          Icons.mail_outline,
          Theme.of(context).colorScheme.onSurfaceVariant,
          'E-posta kaydı yok'
        ),
    };
    return Row(mainAxisSize: MainAxisSize.min, children: [
      Icon(icon, size: 14, color: color),
      const SizedBox(width: 4),
      Text(label,
          style: TextStyle(
              color: color, fontSize: 11.5, fontWeight: FontWeight.w700)),
    ]);
  }
}

/// A single row in a student's real activity log (check-ins, event joins,
/// reviews, ...). Shared by the Settings "Kullanıcı Aktivitesi" category and
/// the XP/journey content it now hosts, so the same activity list is never
/// rendered by two different widgets in two different places.
class ActivityTile extends StatelessWidget {
  final ActivityItem item;
  const ActivityTile({super.key, required this.item});

  IconData get _icon => switch (item.kind) {
        ActivityKind.checkIn => Icons.verified_outlined,
        ActivityKind.eventJoin => Icons.event_available_outlined,
        ActivityKind.review => Icons.star_outline,
        ActivityKind.comment => Icons.mode_comment_outlined,
        ActivityKind.like => Icons.favorite_outline,
        ActivityKind.report => Icons.flag_outlined,
      };

  @override
  Widget build(BuildContext context) {
    const color = ArucadColors.campusGreen;
    return ListTile(
      leading: CircleAvatar(
          backgroundColor: color.withValues(alpha: .14),
          child: Icon(_icon, color: color, size: 20)),
      title:
          Text(item.title, style: const TextStyle(fontWeight: FontWeight.w800)),
      subtitle:
          Text(item.subtitle, maxLines: 2, overflow: TextOverflow.ellipsis),
      // The live timestamp, not `item.meta`. `meta` was written once at
      // creation and never updated — and its only value was ever the
      // literal Turkish 'az önce', so every row in the history claimed to
      // have happened just now, in one language, forever.
      trailing: LiveTimeAgo(
        item.timestamp,
        style: const TextStyle(
            color: ArucadColors.campusGreen,
            fontSize: 11,
            fontWeight: FontWeight.w700),
      ),
    );
  }
}

/// Postage-stamp scalloped frame (Kampüs Nabzı + Yaratıcı Kampüs cards).
class StampCardFrame extends StatelessWidget {
  final Color fill;
  final Color stroke;
  final Widget child;
  final VoidCallback? onTap;
  final double? width;
  final double? height;
  final EdgeInsetsGeometry padding;

  const StampCardFrame({
    super.key,
    required this.fill,
    required this.stroke,
    required this.child,
    this.onTap,
    this.width,
    this.height,
    this.padding = const EdgeInsets.fromLTRB(16, 14, 14, 12),
  });

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        customBorder: const StampCardBorder(),
        child: SizedBox(
          width: width,
          height: height,
          child: Stack(
            fit: StackFit.expand,
            children: [
              CustomPaint(
                painter: StampCardPainter(fill: fill, stroke: stroke),
              ),
              ClipPath(
                clipper: const StampCardClipper(),
                child: Padding(padding: padding, child: child),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class StampCardClipper extends CustomClipper<Path> {
  const StampCardClipper();

  @override
  Path getClip(Size size) => stampCardPath(size);

  @override
  bool shouldReclip(covariant CustomClipper<Path> oldClipper) => false;
}

class StampCardBorder extends ShapeBorder {
  const StampCardBorder();

  @override
  EdgeInsetsGeometry get dimensions => EdgeInsets.zero;

  @override
  Path getInnerPath(Rect rect, {TextDirection? textDirection}) =>
      stampCardPath(rect.size);

  @override
  Path getOuterPath(Rect rect, {TextDirection? textDirection}) =>
      stampCardPath(rect.size).shift(rect.topLeft);

  @override
  void paint(Canvas canvas, Rect rect, {TextDirection? textDirection}) {}

  @override
  ShapeBorder scale(double t) => this;
}

class StampCardPainter extends CustomPainter {
  final Color fill;
  final Color stroke;
  const StampCardPainter({required this.fill, required this.stroke});

  @override
  void paint(Canvas canvas, Size size) {
    final outer = stampCardPath(size);
    canvas.drawPath(
      outer,
      Paint()
        ..color = fill
        ..style = PaintingStyle.fill,
    );
    canvas.drawPath(
      outer,
      Paint()
        ..color = stroke
        ..style = PaintingStyle.stroke
        ..strokeWidth = 2
        ..strokeJoin = StrokeJoin.round,
    );
    const inset = 10.0;
    final inner = RRect.fromRectAndRadius(
      Rect.fromLTWH(
          inset, inset, size.width - inset * 2, size.height - inset * 2),
      const Radius.circular(4),
    );
    canvas.drawRRect(
      inner,
      Paint()
        ..color = stroke.withValues(alpha: .55)
        ..style = PaintingStyle.stroke
        ..strokeWidth = 1.2,
    );
  }

  @override
  bool shouldRepaint(covariant StampCardPainter oldDelegate) =>
      oldDelegate.fill != fill || oldDelegate.stroke != stroke;
}

/// Continuous postage-stamp outline — single closed path (required for ClipPath).
Path stampCardPath(Size size, {double lobe = 5.5}) {
  final w = size.width;
  final h = size.height;
  final path = Path();

  void edge(Offset from, Offset to, Offset outward, {required bool move}) {
    final dx = to.dx - from.dx;
    final dy = to.dy - from.dy;
    final len = math.sqrt(dx * dx + dy * dy);
    final lobes = math.max(3, (len / (lobe * 2.15)).round());
    final step = 1 / lobes;
    for (var i = 0; i < lobes; i++) {
      final t0 = i * step;
      final t1 = (i + 1) * step;
      final a = Offset(from.dx + dx * t0, from.dy + dy * t0);
      final b = Offset(from.dx + dx * t1, from.dy + dy * t1);
      final mid = Offset((a.dx + b.dx) / 2, (a.dy + b.dy) / 2);
      final ctrl =
          Offset(mid.dx + outward.dx * lobe, mid.dy + outward.dy * lobe);
      if (move && i == 0) {
        path.moveTo(a.dx, a.dy);
      } else if (i == 0) {
        path.lineTo(a.dx, a.dy);
      }
      path.quadraticBezierTo(ctrl.dx, ctrl.dy, b.dx, b.dy);
    }
  }

  const pad = 1.5;
  final left = lobe + pad;
  final top = lobe + pad;
  final right = w - lobe - pad;
  final bottom = h - lobe - pad;

  edge(Offset(left, top), Offset(right, top), const Offset(0, -1), move: true);
  edge(Offset(right, top), Offset(right, bottom), const Offset(1, 0),
      move: false);
  edge(Offset(right, bottom), Offset(left, bottom), const Offset(0, 1),
      move: false);
  edge(Offset(left, bottom), Offset(left, top), const Offset(-1, 0),
      move: false);
  path.close();
  return path;
}
