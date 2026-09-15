import 'dart:async';

import 'package:flutter/widgets.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';

/// Elapsed-time labels that are in the reader's language and keep moving.
///
/// Two things were wrong before. The label was hardcoded Turkish — an
/// English or Russian reader saw "5 dk önce" — and it was computed once
/// when the widget built, so a post opened at 14:00 still said "şimdi" at
/// 14:40 unless something else happened to rebuild the screen.
///
/// [LiveTimeAgo] fixes the second by rebuilding itself from a shared
/// ticker, and [formatRelativeTime] fixes the first.

/// A date written the way [language] writes it, in the device's time zone.
///
/// Written out rather than handed to `intl`'s `DateFormat.yMd(locale)`,
/// which throws `LocaleDataException` unless `initializeDateFormatting()`
/// has been awaited first. That is a trap: every label under a month old
/// takes the relative branch, so the crash would only have appeared on a
/// post older than thirty days — in front of a user, not in a test.
String formatDate(DateTime at, AppLanguage language) {
  final local = at.toLocal();
  final d = local.day.toString().padLeft(2, '0');
  final m = local.month.toString().padLeft(2, '0');

  return switch (language) {
    // Turkish and Russian write day first; US English writes month first.
    AppLanguage.tr || AppLanguage.ru => '$d.$m.${local.year}',
    AppLanguage.en => '$m/$d/${local.year}',
  };
}

/// Human-readable elapsed time from [at] to [now], in [language].
///
/// [at] is converted with `toLocal()`, so a UTC timestamp from the API is
/// read against the device's own time zone rather than the server's.
/// Beyond a month the label becomes an absolute date in that language's
/// format rather than one hardcoded d.m.Y.
///
/// [now] exists so the caller can supply the clock: [LiveTimeAgo] passes
/// the shared ticker's value, which is what makes the label advance.
String formatRelativeTime(
  DateTime at, {
  AppLanguage language = AppLanguage.tr,
  DateTime? now,
}) {
  final local = at.toLocal();
  final diff = (now ?? DateTime.now()).difference(local);

  String pick(String tr, String en, String ru) => switch (language) {
        AppLanguage.tr => tr,
        AppLanguage.en => en,
        AppLanguage.ru => ru,
      };

  if (diff.isNegative || diff.inSeconds < 45) {
    return pick('şimdi', 'now', 'сейчас');
  }
  if (diff.inMinutes < 60) {
    final n = diff.inMinutes;
    return pick('$n dk önce', '${n}m ago', '$n мин назад');
  }
  if (diff.inHours < 24) {
    final n = diff.inHours;
    return pick('$n sa önce', '${n}h ago', '$n ч назад');
  }
  if (diff.inDays < 7) {
    final n = diff.inDays;
    return pick('$n g önce', '${n}d ago', '$n дн назад');
  }
  if (diff.inDays < 30) {
    final weeks = (diff.inDays / 7).floor().clamp(1, 4);
    return pick('$weeks hf önce', '${weeks}w ago', '$weeks нед назад');
  }

  return formatDate(local, language);
}

/// A date and time written the way [language] writes them, in the device's
/// own time zone. 24-hour clock in all three — Turkish and Russian use it,
/// and so does the rest of this app's UI.
String formatDateTime(DateTime at, {AppLanguage language = AppLanguage.tr}) {
  final local = at.toLocal();
  final h = local.hour.toString().padLeft(2, '0');
  final min = local.minute.toString().padLeft(2, '0');

  return '${formatDate(local, language)} $h:$min';
}

/// One timer for the whole app, driving every relative timestamp on screen.
///
/// A `Timer` per label would mean dozens of them on a busy feed, all doing
/// the same thing a moment apart. This ticks once and notifies; each
/// [LiveTimeAgo] rebuilds only itself.
///
/// Thirty seconds is chosen against the shortest label that can change: the
/// jump from "now" to "1m ago" happens at 45 seconds, so no label is ever
/// more than half a minute stale, and nothing below a minute is ever shown.
class TimeAgoTicker {
  TimeAgoTicker._();

  static final ValueNotifier<DateTime> now = ValueNotifier(DateTime.now());

  static Timer? _timer;
  static int _listeners = 0;

  /// Started by the first label on screen and stopped by the last, so a
  /// screen with no timestamps costs nothing and a backgrounded app is not
  /// left with a timer running against a value nobody reads.
  static void _attach() {
    _listeners++;
    _timer ??= Timer.periodic(
      const Duration(seconds: 30),
      (_) => now.value = DateTime.now(),
    );
  }

  static void _detach() {
    _listeners--;
    if (_listeners <= 0) {
      _listeners = 0;
      _timer?.cancel();
      _timer = null;
    }
  }

  @visibleForTesting
  static bool get isRunning => _timer != null;

  /// Moves every live label on as though [elapsed] had passed. Tests should
  /// not have to wait thirty real seconds to see a label change.
  @visibleForTesting
  static void advanceForTest(Duration elapsed) =>
      now.value = DateTime.now().add(elapsed);
}

/// A relative timestamp that updates itself.
///
/// Drop-in for `Text(formatRelativeTime(...))`. The label re-renders on the
/// shared tick, so "now" becomes "1m ago" while the reader is looking at
/// it, without the screen being rebuilt or refreshed.
class LiveTimeAgo extends StatefulWidget {
  final DateTime at;
  final TextStyle? style;
  final TextAlign? textAlign;

  const LiveTimeAgo(this.at, {super.key, this.style, this.textAlign});

  @override
  State<LiveTimeAgo> createState() => _LiveTimeAgoState();
}

class _LiveTimeAgoState extends State<LiveTimeAgo> {
  @override
  void initState() {
    super.initState();
    TimeAgoTicker._attach();
  }

  @override
  void dispose() {
    TimeAgoTicker._detach();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final language = AppLocale.languageOf(context);

    return ValueListenableBuilder<DateTime>(
      valueListenable: TimeAgoTicker.now,
      // The tick's own value is the clock, not `DateTime.now()`. Reading
      // the wall clock here would rebuild on every tick and still print the
      // same thing under a fake clock — the label looked live and was not.
      builder: (context, tick, _) => Text(
        formatRelativeTime(widget.at, language: language, now: tick),
        style: widget.style,
        textAlign: widget.textAlign,
      ),
    );
  }
}
