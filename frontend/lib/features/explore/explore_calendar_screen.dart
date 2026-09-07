import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/explore/explore_calendar_models.dart';
import 'package:arucad_campus_prototype/features/home/event_detail_screen.dart';
import 'package:arucad_campus_prototype/features/profile/my_applications_screen.dart';
import 'package:arucad_campus_prototype/features/services/appointment_booking_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

class ExploreCalendarScreen extends StatefulWidget {
  final CampusRepository repository;
  final MapProvider mapProvider;
  final AnalyticsTracker analyticsTracker;
  final DateTime? initialDay;

  const ExploreCalendarScreen({
    super.key,
    required this.repository,
    required this.mapProvider,
    required this.analyticsTracker,
    this.initialDay,
  });

  @override
  State<ExploreCalendarScreen> createState() => _ExploreCalendarScreenState();
}

class _ExploreCalendarScreenState extends State<ExploreCalendarScreen> {
  bool _loading = true;
  List<ExploreCalendarItem> _items = const [];
  ExploreCalendarFilter _filter = ExploreCalendarFilter.all;
  late DateTime _visibleMonth;
  late DateTime _selectedDay;
  int _visibleDayCount = kPageSize;

  @override
  void initState() {
    super.initState();
    final now = DateTime.now();
    final seed = widget.initialDay ?? now;
    _selectedDay = DateTime(seed.year, seed.month, seed.day);
    _visibleMonth = DateTime(seed.year, seed.month);
    _load();
  }

  Future<void> _load() async {
    final items = await loadExploreCalendarItems(widget.repository);
    if (!mounted) return;
    setState(() {
      _items = items;
      _loading = false;
    });
  }

  List<ExploreCalendarItem> get _filtered =>
      _items.where((i) => i.matches(_filter)).toList();

  List<ExploreCalendarItem> _forDay(DateTime day) {
    final key = DateTime(day.year, day.month, day.day);
    return _filtered.where((i) => i.day == key).toList();
  }

  Set<ExploreCalendarKind> _kindsOnDay(DateTime day) {
    return _forDay(day).map((i) => i.kind).toSet();
  }

  void _openItem(ExploreCalendarItem item) {
    switch (item.kind) {
      case ExploreCalendarKind.appointment:
        Navigator.of(context).push(MaterialPageRoute(
          builder: (_) =>
              AppointmentBookingScreen(repository: widget.repository),
        ));
      case ExploreCalendarKind.application:
        Navigator.of(context).push(MaterialPageRoute(
          builder: (_) => MyApplicationsScreen(repository: widget.repository),
        ));
      case ExploreCalendarKind.event:
        final event = item.event;
        if (event == null) return;
        Navigator.of(context).push(MaterialPageRoute(
          builder: (_) => EventDetailScreen(
            event: event,
            repository: widget.repository,
            mapProvider: widget.mapProvider,
            analyticsTracker: widget.analyticsTracker,
          ),
        ));
    }
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final dayItems = _forDay(_selectedDay);
    final dayShown = _visibleDayCount.clamp(0, dayItems.length);
    final dayPage = dayItems.take(dayShown).toList();
    final monthLabel =
        DateFormat.yMMMM(Localizations.localeOf(context).toString())
            .format(_visibleMonth);

    return Scaffold(
      appBar: AppBar(title: Text(strings.t('discover_calendar'))),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.fromLTRB(20, 12, 20, 28),
                children: [
                  SingleChildScrollView(
                    scrollDirection: Axis.horizontal,
                    child: Row(
                      children: [
                        for (final f in ExploreCalendarFilter.values) ...[
                          Padding(
                            padding: const EdgeInsets.only(right: 8),
                            child: _FilterToneChip(
                              label: switch (f) {
                                ExploreCalendarFilter.all =>
                                  strings.t('explore_cal_filter_all'),
                                ExploreCalendarFilter.appointment =>
                                  strings.t('explore_cal_filter_appointment'),
                                ExploreCalendarFilter.application =>
                                  strings.t('explore_cal_filter_application'),
                                ExploreCalendarFilter.event =>
                                  strings.t('explore_cal_filter_event'),
                              },
                              color: ExploreCalendarItem.filterColor(f),
                              onColor: ExploreCalendarItem.filterOnColor(f),
                              selected: _filter == f,
                              onTap: () => setState(() {
                                _filter = f;
                                _visibleDayCount = kPageSize;
                              }),
                            ),
                          ),
                        ],
                      ],
                    ),
                  ),
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      IconButton(
                        onPressed: () => setState(() {
                          _visibleMonth = DateTime(
                              _visibleMonth.year, _visibleMonth.month - 1);
                        }),
                        icon: const Icon(Icons.chevron_left),
                      ),
                      Expanded(
                        child: Text(
                          monthLabel,
                          textAlign: TextAlign.center,
                          style: const TextStyle(
                              fontWeight: FontWeight.w900, fontSize: 16),
                        ),
                      ),
                      IconButton(
                        onPressed: () => setState(() {
                          _visibleMonth = DateTime(
                              _visibleMonth.year, _visibleMonth.month + 1);
                        }),
                        icon: const Icon(Icons.chevron_right),
                      ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  _MonthGrid(
                    month: _visibleMonth,
                    selected: _selectedDay,
                    kindsForDay: _kindsOnDay,
                    onSelect: (day) => setState(() {
                      _selectedDay = day;
                      _visibleDayCount = kPageSize;
                    }),
                  ),
                  const SizedBox(height: 18),
                  Text(
                    '${strings.t('explore_cal_selected')} · '
                    '${_selectedDay.day}.${_selectedDay.month}.${_selectedDay.year}',
                    style: const TextStyle(fontWeight: FontWeight.w900),
                  ),
                  const SizedBox(height: 10),
                  if (dayItems.isEmpty)
                    Text(strings.t('explore_cal_empty_day'),
                        style: const TextStyle(
                            color: ArucadColors.muted, fontSize: 13))
                  else ...[
                    for (final item in dayPage)
                      Padding(
                        padding: const EdgeInsets.only(bottom: 8),
                        child: _CalendarListTile(
                          item: item,
                          onTap: () => _openItem(item),
                        ),
                      ),
                    LoadMoreButton(
                      shown: dayShown,
                      total: dayItems.length,
                      itemLabel: 'kayıt',
                      onTap: () =>
                          setState(() => _visibleDayCount += kPageSize),
                    ),
                  ],
                ],
              ),
            ),
    );
  }
}

class _FilterToneChip extends StatelessWidget {
  final String label;
  final Color color;
  final Color onColor;
  final bool selected;
  final VoidCallback onTap;

  const _FilterToneChip({
    required this.label,
    required this.color,
    required this.onColor,
    required this.selected,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.transparent,
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(999),
        child: Ink(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
          decoration: BoxDecoration(
            color: selected ? color : color.withValues(alpha: .12),
            borderRadius: BorderRadius.circular(999),
            border: Border.all(
              color: selected ? color : color.withValues(alpha: .35),
            ),
          ),
          child: Text(
            label,
            style: TextStyle(
              color: selected ? onColor : color,
              fontWeight: FontWeight.w800,
              fontSize: 13,
            ),
          ),
        ),
      ),
    );
  }
}

class _CalendarListTile extends StatelessWidget {
  final ExploreCalendarItem item;
  final VoidCallback onTap;

  const _CalendarListTile({required this.item, required this.onTap});

  @override
  Widget build(BuildContext context) {
    return Material(
      color: ArucadColors.paper,
      borderRadius: BorderRadius.circular(14),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(14),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
          child: Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: item.color.withValues(alpha: .16),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Icon(item.icon, color: item.color, size: 22),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      item.title,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        color: ArucadColors.ink,
                        fontWeight: FontWeight.w800,
                        fontSize: 14.5,
                      ),
                    ),
                    const SizedBox(height: 2),
                    Text(
                      item.subtitle,
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: const TextStyle(
                        color: ArucadColors.muted,
                        fontSize: 12.5,
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                  ],
                ),
              ),
              Text(
                DateFormat('HH:mm').format(item.at),
                style: const TextStyle(
                  color: ArucadColors.ink,
                  fontWeight: FontWeight.w700,
                  fontSize: 12,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _MonthGrid extends StatelessWidget {
  final DateTime month;
  final DateTime selected;
  final Set<ExploreCalendarKind> Function(DateTime day) kindsForDay;
  final ValueChanged<DateTime> onSelect;

  const _MonthGrid({
    required this.month,
    required this.selected,
    required this.kindsForDay,
    required this.onSelect,
  });

  Color _kindColor(ExploreCalendarKind kind) => switch (kind) {
        ExploreCalendarKind.appointment => ArucadColors.blue,
        ExploreCalendarKind.application => ArucadColors.yellow,
        ExploreCalendarKind.event => ArucadColors.campusGreen,
      };

  String _kindHint(ExploreCalendarKind kind) => switch (kind) {
        ExploreCalendarKind.appointment => 'R',
        ExploreCalendarKind.application => 'B',
        ExploreCalendarKind.event => 'E',
      };

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final first = DateTime(month.year, month.month, 1);
    final daysInMonth = DateTime(month.year, month.month + 1, 0).day;
    final leading = (first.weekday + 6) % 7;
    final totalCells = ((leading + daysInMonth + 6) ~/ 7) * 7;
    final labels = ['Pzt', 'Sal', 'Çar', 'Per', 'Cum', 'Cmt', 'Paz'];

    return Column(
      children: [
        Row(
          children: [
            for (final label in labels)
              Expanded(
                child: Center(
                  child: Text(label,
                      style: TextStyle(
                          fontSize: 11,
                          fontWeight: FontWeight.w700,
                          color: scheme.onSurfaceVariant)),
                ),
              ),
          ],
        ),
        const SizedBox(height: 6),
        GridView.builder(
          shrinkWrap: true,
          physics: const NeverScrollableScrollPhysics(),
          itemCount: totalCells,
          gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
            crossAxisCount: 7,
            mainAxisSpacing: 4,
            crossAxisSpacing: 4,
            childAspectRatio: 0.82,
          ),
          itemBuilder: (context, index) {
            final dayNum = index - leading + 1;
            if (dayNum < 1 || dayNum > daysInMonth) {
              return const SizedBox.shrink();
            }
            final day = DateTime(month.year, month.month, dayNum);
            final kinds = kindsForDay(day);
            final isSelected = day.year == selected.year &&
                day.month == selected.month &&
                day.day == selected.day;
            final ordered = [
              ExploreCalendarKind.appointment,
              ExploreCalendarKind.application,
              ExploreCalendarKind.event,
            ].where(kinds.contains).toList();

            return InkWell(
              onTap: () => onSelect(day),
              borderRadius: BorderRadius.circular(10),
              child: DecoratedBox(
                decoration: BoxDecoration(
                  color: isSelected
                      ? const Color(0x14000000)
                      : null,
                  borderRadius: BorderRadius.circular(10),
                  border: isSelected
                      ? Border.all(color: ArucadColors.ink, width: 1.2)
                      : null,
                  boxShadow: isSelected
                      ? const [
                          BoxShadow(
                            color: Color(0x1A000000),
                            blurRadius: 8,
                            offset: Offset(0, 2),
                          ),
                        ]
                      : null,
                ),
                child: Padding(
                  padding: const EdgeInsets.symmetric(vertical: 4),
                  child: Column(
                    children: [
                      Text(
                        '$dayNum',
                        style: TextStyle(
                          fontWeight: isSelected
                              ? FontWeight.w900
                              : FontWeight.w600,
                          color: ArucadColors.ink,
                          fontSize: 13,
                        ),
                      ),
                      const Spacer(),
                      if (ordered.isNotEmpty)
                        Wrap(
                          spacing: 2,
                          runSpacing: 2,
                          alignment: WrapAlignment.center,
                          children: [
                            for (final kind in ordered)
                              Container(
                                width: 14,
                                height: 14,
                                alignment: Alignment.center,
                                decoration: BoxDecoration(
                                  color: _kindColor(kind),
                                  borderRadius: BorderRadius.circular(4),
                                ),
                                child: Text(
                                  _kindHint(kind),
                                  style: TextStyle(
                                    color: kind ==
                                            ExploreCalendarKind.application
                                        ? ArucadColors.ink
                                        : Colors.white,
                                    fontSize: 8.5,
                                    fontWeight: FontWeight.w900,
                                    height: 1,
                                  ),
                                ),
                              ),
                          ],
                        ),
                      const SizedBox(height: 2),
                    ],
                  ),
                ),
              ),
            );
          },
        ),
      ],
    );
  }
}
