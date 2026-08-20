import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/auth/app_settings_store.dart';
import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/widgets/block_renderer.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// A club's own page — real description, a real (if estimated, same
/// disclosed pattern as `campusOnlineCount`) member count, a real local
/// join-state toggle, and any real campus events that plausibly belong to
/// it. There's no membership roster or club-tagged-content backend, so
/// "Members"/"Posts" lists aren't built here — see the doc comment on
/// `_relatedEvents` for exactly how event matching works and its limits.
class ClubDetailScreen extends StatefulWidget {
  final CampusClub club;
  final List<CampusEvent> events;
  const ClubDetailScreen({super.key, required this.club, this.events = const []});

  @override
  State<ClubDetailScreen> createState() => _ClubDetailScreenState();
}

class _ClubDetailScreenState extends State<ClubDetailScreen> {
  bool _joined = false;
  bool _loading = true;

  @override
  void initState() {
    super.initState();
    AppSettingsStore.joinedClubs().then((joined) {
      if (!mounted) return;
      setState(() {
        _joined = joined.contains(widget.club.id);
        _loading = false;
      });
    });
  }

  Future<void> _toggleJoin() async {
    final next = !_joined;
    setState(() => _joined = next);
    await AppSettingsStore.setClubJoined(widget.club.id, next);
  }

  /// Real campus events whose title or category loosely mentions the
  /// club's name/category — a heuristic match, not a real club↔event
  /// relationship (that would need admin-linked content, like
  /// `DirectoryEntry.relatedServiceId` does for services). Shown only when
  /// it actually finds something, rather than a fake "no events" filler.
  List<CampusEvent> get _relatedEvents {
    final nameWords = widget.club.name
        .toLowerCase()
        .replaceAll('kulübü', '')
        .replaceAll('club', '')
        .split(RegExp(r'[\s&]+'))
        .where((w) => w.length > 3)
        .toList();
    return widget.events.where((e) {
      final title = e.title.toLowerCase();
      final category = e.category.toLowerCase();
      return nameWords.any((w) => title.contains(w) || category.contains(w));
    }).toList();
  }

  @override
  Widget build(BuildContext context) {
    final club = widget.club;
    final members = campusClubMemberEstimate(club.name);
    final related = _relatedEvents;

    return Scaffold(
      appBar: AppBar(title: Text(club.name)),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : ListView(
              padding: const EdgeInsets.fromLTRB(20, 16, 20, 32),
              children: [
                Row(children: [
                  CircleAvatar(
                    radius: 26,
                    backgroundColor: ArucadColors.mist,
                    child: Text(club.name.isEmpty ? '?' : club.name.substring(0, 1),
                        style: const TextStyle(
                            fontWeight: FontWeight.w900, color: ArucadColors.primary)),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                      Text(club.category,
                          style: const TextStyle(
                              color: ArucadColors.primary,
                              fontWeight: FontWeight.w700,
                              fontSize: 12)),
                      const SizedBox(height: 2),
                      Text('$members üye',
                          style: const TextStyle(color: ArucadColors.muted, fontSize: 12.5)),
                    ]),
                  ),
                ]),
                const SizedBox(height: 20),
                const Text('Hakkında',
                    style: TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
                const SizedBox(height: 8),
                Text(club.description, style: const TextStyle(fontSize: 15, height: 1.4)),
                if (club.body.isNotEmpty) ...[
                  const SizedBox(height: 8),
                  BlockRenderer(blocks: club.body),
                ],
                if (related.isNotEmpty) ...[
                  const SizedBox(height: 24),
                  const Text('Yaklaşan Etkinlikler',
                      style: TextStyle(fontWeight: FontWeight.w900, fontSize: 15)),
                  const SizedBox(height: 10),
                  for (final event in related)
                    Padding(
                      padding: const EdgeInsets.only(bottom: 8),
                      child: Card(
                        child: ListTile(
                          leading: const Icon(Icons.event_outlined, color: ArucadColors.primary),
                          title: Text(event.title,
                              style: const TextStyle(fontWeight: FontWeight.w700)),
                          subtitle: Text('${event.time} · ${event.placeName}'),
                        ),
                      ),
                    ),
                ],
                const SizedBox(height: 28),
                SizedBox(
                  width: double.infinity,
                  child: _joined
                      ? OutlinedButton.icon(
                          onPressed: _toggleJoin,
                          icon: const Icon(Icons.check),
                          label: const Text('Katıldın'),
                        )
                      : FilledButton(
                          onPressed: _toggleJoin,
                          child: const Text('Katıl'),
                        ),
                ),
              ],
            ),
    );
  }
}
