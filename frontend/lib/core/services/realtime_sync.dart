import 'dart:async';

import 'package:flutter/widgets.dart';

import 'contracts.dart';

/// Polls the durable `realtime_events` log and notifies subscribed
/// screens. First handshake (`after=0`) only captures the cursor so a
/// freshly opened app doesn't replay yesterday; later ticks are live.
class RealtimeBus extends ChangeNotifier {
  RealtimeBus(this.repository);
  final CampusRepository repository;

  int? _cursor;
  Timer? _timer;
  bool _inFlight = false;
  List<String> lastTypes = const [];

  void start() {
    _timer?.cancel();
    unawaited(_tick());
    _timer = Timer.periodic(const Duration(seconds: 3), (_) => unawaited(_tick()));
  }

  void stop() {
    _timer?.cancel();
    _timer = null;
  }

  Future<void> _tick() async {
    if (_inFlight) return;
    _inFlight = true;
    try {
      final poll = await repository.pollRealtimeEvents(after: _cursor);
      _cursor = poll.cursor;
      if (poll.events.isEmpty) return;
      lastTypes = poll.events.map((e) => e.type).toList();
      notifyListeners();
    } catch (_) {
      // A blip (expired token, backend restart) must not crash the UI —
      // the next tick retries with the same cursor.
    } finally {
      _inFlight = false;
    }
  }

  bool saw(Iterable<String> types) {
    final wanted = types.toSet();
    return lastTypes.any(wanted.contains);
  }

  @override
  void dispose() {
    stop();
    super.dispose();
  }
}

class RealtimeScope extends InheritedNotifier<RealtimeBus> {
  const RealtimeScope({super.key, required RealtimeBus bus, required super.child})
      : super(notifier: bus);

  static RealtimeBus? of(BuildContext context) =>
      context.dependOnInheritedWidgetOfExactType<RealtimeScope>()?.notifier;

  /// Does not subscribe the caller to rebuilds — [RealtimeAware] uses
  /// this plus [RealtimeBus.addListener] so screens refresh data without
  /// rebuilding the whole tree on every poll.
  static RealtimeBus? maybeOf(BuildContext context) =>
      context.getInheritedWidgetOfExactType<RealtimeScope>()?.notifier;
}

/// Screens that should refresh when matching event types arrive.
mixin RealtimeAware<T extends StatefulWidget> on State<T> {
  RealtimeBus? _rtBus;

  /// Exact event types this screen cares about, e.g. `post.created`.
  Set<String> get realtimeTypes;

  void onRealtimeEvents(List<String> types);

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final bus = RealtimeScope.maybeOf(context);
    if (identical(bus, _rtBus)) return;
    _rtBus?.removeListener(_onRealtime);
    _rtBus = bus;
    _rtBus?.addListener(_onRealtime);
  }

  void _onRealtime() {
    final bus = _rtBus;
    if (bus == null || !mounted) return;
    if (bus.saw(realtimeTypes)) onRealtimeEvents(bus.lastTypes);
  }

  @override
  void dispose() {
    _rtBus?.removeListener(_onRealtime);
    super.dispose();
  }
}
