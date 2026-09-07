import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/place/place_detail_screen.dart';
import 'package:arucad_campus_prototype/features/social/social_profile_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_avatar.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// Yearly XP goal shown as the progress bar's denominator — an editorial
/// target (not a hard cap), same idea as a fitness app's yearly goal.
const _yearGoalXp = 3000;

class QuestsScreen extends StatefulWidget {
  final CampusRepository repository;
  final CampusUser user;
  final MapProvider? mapProvider;
  final AnalyticsTracker? analyticsTracker;
  final bool showSuggestions;

  const QuestsScreen({
    super.key,
    required this.repository,
    required this.user,
    this.mapProvider,
    this.analyticsTracker,
    this.showSuggestions = true,
  });

  @override
  State<QuestsScreen> createState() => _QuestsScreenState();
}

class _QuestsScreenState extends State<QuestsScreen> {
  bool _loading = true;
  List<Quest> _quests = const [];
  List<ActivityItem> _activity = const [];
  List<LeaderboardEntry> _leaderboard = const [];
  List<CampusPlace> _places = const [];
  List<CampusEvent> _events = const [];
  final _searchController = TextEditingController();
  String _search = '';
  int _visibleLeaders = kPageSize;
  int _selectedYear = DateTime.now().year;

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final results = await Future.wait([
      widget.repository.getQuests(),
      widget.repository.getMyActivity(),
      widget.repository.getLeaderboard(),
      widget.repository.getPlaces(),
      widget.repository.getEvents(),
    ]);
    if (!mounted) return;
    setState(() {
      _quests = results[0] as List<Quest>;
      _activity = results[1] as List<ActivityItem>;
      _leaderboard = results[2] as List<LeaderboardEntry>;
      _places = results[3] as List<CampusPlace>;
      _events = results[4] as List<CampusEvent>;
      _loading = false;
    });
  }

  int get _yearXp => _activity
      .where((a) => a.timestamp.year == _selectedYear)
      .fold(0, (sum, a) => sum + a.xp);

  /// Every year with real recorded activity, newest first, plus the
  /// current year even if it's still empty — so "geçmiş yılları görebilme"
  /// (#8) always has at least today's year to select.
  List<int> get _availableYears {
    final years = _activity.map((a) => a.timestamp.year).toSet()
      ..add(DateTime.now().year);
    return years.toList()..sort((a, b) => b.compareTo(a));
  }

  Set<String> get _visitedPlaceNames => _activity
      .where((a) => a.kind == ActivityKind.checkIn)
      .map((a) => a.title.replaceFirst('Check-in: ', '').toLowerCase())
      .toSet();

  Set<String> get _joinedEventTitles => _activity
      .where((a) => a.kind == ActivityKind.eventJoin)
      .map((a) => a.title.replaceFirst('Katıldın: ', '').toLowerCase())
      .toSet();

  /// Yearly activity grouped into 4 real categories — a usage summary, not
  /// a value judgment. Deliberately *not* a single "how good a student are
  /// you" percentage: that framing reads as exclusionary in a university
  /// app, so this stays a breakdown of what a student actually did, with
  /// no category scored against the others.
  Map<String, int> get _journeyCounts {
    final thisYear = _activity.where((a) => a.timestamp.year == _selectedYear);
    return {
      'Explore': thisYear.where((a) => a.kind == ActivityKind.checkIn).length,
      'Connect': thisYear
          .where((a) => a.kind == ActivityKind.comment || a.kind == ActivityKind.like)
          .length,
      'Participate': thisYear.where((a) => a.kind == ActivityKind.eventJoin).length,
      'Contribute': thisYear.where((a) => a.kind == ActivityKind.review).length,
    };
  }

  @override
  Widget build(BuildContext context) {
    if (_loading) {
      return const Center(child: CircularProgressIndicator());
    }
    final strings = AppLocale.of(context);
    final year = _selectedYear;
    final years = _availableYears;
    final unvisited = _places
        .where((p) => !_visitedPlaceNames.contains(p.name.toLowerCase()))
        .take(3)
        .toList();
    final unjoined = _events
        .where((e) => !_joinedEventTitles.contains(e.title.toLowerCase()))
        .take(2)
        .toList();

    return Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 760),
        child: RefreshIndicator(
          onRefresh: _load,
          child: ListView(
            padding: const EdgeInsets.fromLTRB(20, 18, 20, 24),
            children: [
              Text(strings.t('quests_title'),
                  style: Theme.of(context)
                      .textTheme
                      .headlineSmall
                      ?.copyWith(fontWeight: FontWeight.w900)),
              const SizedBox(height: 8),
              Text(strings.t('quests_tagline')),
              if (years.length > 1) ...[
                const SizedBox(height: 14),
                SizedBox(
                  height: 34,
                  child: ListView.separated(
                    scrollDirection: Axis.horizontal,
                    itemCount: years.length,
                    separatorBuilder: (_, __) => const SizedBox(width: 6),
                    itemBuilder: (context, i) => SelectableChip(
                      label: '${years[i]}',
                      selected: years[i] == _selectedYear,
                      onSelected: (_) => setState(() => _selectedYear = years[i]),
                    ),
                  ),
                ),
              ],
              const SizedBox(height: 18),
              Card(
                color: ArucadColors.primary,
                child: Padding(
                  padding: const EdgeInsets.all(20),
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Row(children: [
                      Expanded(
                        child: Text('${strings.t('quests_year_score')} · $year',
                            style: const TextStyle(
                                color: Colors.white70, fontWeight: FontWeight.w800)),
                      ),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                        decoration: BoxDecoration(
                          color: levelColor(widget.user.level),
                          borderRadius: BorderRadius.circular(999),
                          border: Border.all(color: Colors.white24),
                        ),
                        child: Text('LEVEL ${widget.user.level}',
                            style: ArucadTextStyles.display(
                                color: onAccent(levelColor(widget.user.level)),
                                fontWeight: FontWeight.w900,
                                fontSize: 11,
                                letterSpacing: .4)),
                      ),
                    ]),
                    const SizedBox(height: 8),
                    Text('${widget.user.xp} XP toplam',
                        style: const TextStyle(color: Colors.white70, fontSize: 12)),
                    Text('$_yearXp XP',
                        style: const TextStyle(
                            fontSize: 34, fontWeight: FontWeight.w900, color: Colors.white)),
                    const SizedBox(height: 10),
                    LinearProgressIndicator(
                        value: (_yearXp / _yearGoalXp).clamp(0, 1),
                        backgroundColor: Colors.white24,
                        color: Colors.white),
                    const SizedBox(height: 8),
                    Text('Hedef: $_yearGoalXp XP · her yıl başında sıfırlanır, geçmiş istatistiklerin kalır',
                        style: const TextStyle(color: Colors.white70, fontSize: 12)),
                  ]),
                ),
              ),
              const SizedBox(height: 14),
              _CampusJourneyCard(year: year, counts: _journeyCounts),
              const SizedBox(height: 18),
              ..._quests.map((quest) => Padding(
                    padding: const EdgeInsets.only(bottom: 12),
                    child: Card(
                      child: Padding(
                        padding: const EdgeInsets.all(18),
                        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                          Row(children: [
                            Expanded(child: Text(quest.title, style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 18))),
                            Text('+${quest.reward} XP', style: const TextStyle(fontWeight: FontWeight.w900, color: ArucadColors.yellow)),
                          ]),
                          const SizedBox(height: 6),
                          Text(quest.subtitle),
                          const SizedBox(height: 14),
                          LinearProgressIndicator(value: quest.progress / quest.target.toDouble()),
                          const SizedBox(height: 6),
                          Text('${quest.progress}/${quest.target} tamamlandı'),
                        ]),
                      ),
                    ),
                  )),
              if (widget.showSuggestions &&
                  (unvisited.isNotEmpty || unjoined.isNotEmpty)) ...[
                const SizedBox(height: 6),
                Text(strings.t('quests_suggestions'),
                    style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
                const SizedBox(height: 8),
                Card(
                  child: Column(children: [
                    for (final place in unvisited)
                      ListTile(
                        leading: const Icon(Icons.explore_outlined, color: ArucadColors.primary),
                        title: Text('${place.name} henüz gitmedin'),
                        subtitle: const Text('Check-in yaparsan +30 XP kazanırsın'),
                        trailing: const Icon(Icons.chevron_right),
                        onTap: widget.mapProvider == null || widget.analyticsTracker == null
                            ? null
                            : () => Navigator.of(context).push(MaterialPageRoute(
                                builder: (_) => PlaceDetailScreen(
                                    place: place,
                                    repository: widget.repository,
                                    mapProvider: widget.mapProvider!,
                                    analyticsTracker: widget.analyticsTracker!))),
                      ),
                    for (final event in unjoined)
                      ListTile(
                        leading: const Icon(Icons.event_available_outlined, color: ArucadColors.blue),
                        title: Text('${event.title} var, katılmak ister misin?'),
                        subtitle: Text('${event.placeName} · ${event.time} · +${event.xp} XP'),
                      ),
                  ]),
                ),
                const SizedBox(height: 18),
              ],
              Text(strings.t('quests_leaderboard'),
                  style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
              const SizedBox(height: 8),
              TextField(
                controller: _searchController,
                decoration: InputDecoration(
                  hintText: 'İsim ara...',
                  prefixIcon: const Icon(Icons.search),
                  filled: true,
                  fillColor: ArucadColors.paper,
                  border: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(14),
                    borderSide: BorderSide.none,
                  ),
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(14),
                    borderSide: BorderSide.none,
                  ),
                  focusedBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(14),
                    borderSide: const BorderSide(
                        color: ArucadColors.ink, width: 1.2),
                  ),
                  suffixIcon: _search.isEmpty
                      ? null
                      : IconButton(
                          icon: const Icon(Icons.close),
                          onPressed: () => setState(() {
                            _searchController.clear();
                            _search = '';
                            _visibleLeaders = kPageSize;
                          }),
                        ),
                ),
                onChanged: (value) => setState(() {
                  _search = value;
                  _visibleLeaders = kPageSize;
                }),
              ),
              const SizedBox(height: 10),
              Builder(builder: (context) {
                final filtered = _search.isEmpty
                    ? _leaderboard
                    : _leaderboard
                        .where((e) =>
                            e.name.toLowerCase().contains(_search.toLowerCase()))
                        .toList();
                // Ranks always reflect the full (unfiltered) standings, not
                // the filtered list's position.
                final ranks = {
                  for (var i = 0; i < _leaderboard.length; i++) _leaderboard[i]: i + 1
                };
                final shown = _visibleLeaders.clamp(0, filtered.length);
                return Column(children: [
                  Card(
                    child: filtered.isEmpty
                        ? const Padding(
                            padding: EdgeInsets.all(18),
                            child: Text('Eşleşen kimse yok',
                                style: TextStyle(color: ArucadColors.muted)),
                          )
                        : Column(children: [
                            for (var i = 0; i < shown; i++) ...[
                              if (i > 0) const Divider(height: 1),
                              _LeaderboardRow(
                                rank: ranks[filtered[i]]!,
                                entry: filtered[i],
                                onTap: filtered[i].isMe
                                    ? null
                                    : () => Navigator.of(context).push(
                                        MaterialPageRoute(
                                            builder: (_) => SocialProfileScreen(
                                                repository: widget.repository,
                                                viewedUserName:
                                                    filtered[i].name))),
                              ),
                            ],
                          ]),
                  ),
                  if (filtered.isNotEmpty)
                    LoadMoreButton(
                      shown: shown,
                      total: filtered.length,
                      itemLabel: 'kişi',
                      onTap: () => setState(() => _visibleLeaders += kPageSize),
                    ),
                ]);
              }),
            ],
          ),
        ),
      ),
    );
  }
}

/// A usage summary, deliberately separate from the XP/Score card above —
/// see `_journeyCounts`' doc comment for why this stays 4 neutral
/// categories instead of one composite "how good a student are you"
/// number.
// One distinct accent per category — a single-color bar chart reads as
// "all the same thing measured differently," not four real, separate
// kinds of campus activity.
const _journeyCategoryColors = {
  'Explore': ArucadColors.red,
  'Connect': ArucadColors.blue,
  'Participate': ArucadColors.yellow,
  'Contribute': ArucadColors.campusGreen,
};

class _CampusJourneyCard extends StatelessWidget {
  final int year;
  final Map<String, int> counts;
  const _CampusJourneyCard({required this.year, required this.counts});

  @override
  Widget build(BuildContext context) {
    final maxCount = counts.values.fold(0, (m, v) => v > m ? v : m);
    final total = counts.values.fold(0, (sum, v) => sum + v);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text('$year Campus Journey',
              style: const TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
          const SizedBox(height: 4),
          const Text(
              'Skor XP\'yi ölçer; bu ise bu yıl kampüsü nasıl kullandığını gösterir.',
              style: TextStyle(color: ArucadColors.muted, fontSize: 12)),
          const SizedBox(height: 16),
          if (total == 0)
            const Text('Bu yıl henüz kayıtlı bir aktivite yok.',
                style: TextStyle(color: ArucadColors.muted))
          else
            for (final entry in counts.entries)
              Padding(
                padding: const EdgeInsets.only(bottom: 10),
                child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                  Row(children: [
                    Expanded(
                        child: Text(entry.key,
                            style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13))),
                    Text('${entry.value}',
                        style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13)),
                  ]),
                  const SizedBox(height: 4),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(999),
                    child: LinearProgressIndicator(
                      value: maxCount == 0 ? 0 : entry.value / maxCount,
                      minHeight: 7,
                      backgroundColor: ArucadColors.mist,
                      color: _journeyCategoryColors[entry.key] ?? ArucadColors.primary,
                    ),
                  ),
                ]),
              ),
        ]),
      ),
    );
  }
}

class _LeaderboardRow extends StatelessWidget {
  final int rank;
  final LeaderboardEntry entry;
  final VoidCallback? onTap;
  const _LeaderboardRow(
      {required this.rank, required this.entry, this.onTap});

  @override
  Widget build(BuildContext context) {
    assert(rank >= 1);
    return ListTile(
      onTap: onTap,
      tileColor: entry.isMe ? ArucadColors.blue.withValues(alpha: .08) : null,
      leading: CampusAvatar(name: entry.name, avatarUrl: entry.avatarUrl),
      title: Text(entry.name,
          style: TextStyle(
              fontWeight: entry.isMe ? FontWeight.w900 : FontWeight.w700)),
      trailing: Text('${entry.xp} XP',
          style: const TextStyle(
              fontWeight: FontWeight.w900, color: ArucadColors.blue)),
    );
  }
}
