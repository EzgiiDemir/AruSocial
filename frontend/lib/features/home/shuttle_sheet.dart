import 'dart:async';

import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/config/shuttle_config.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

Future<void> showShuttleSheet(BuildContext context,
    {required CampusRepository repository}) {
  return showModalBottomSheet<void>(
    context: context,
    backgroundColor: Colors.transparent,
    isScrollControlled: true,
    builder: (_) => ShuttleSheet(repository: repository),
  );
}

class ShuttleSheet extends StatefulWidget {
  final CampusRepository repository;
  const ShuttleSheet({super.key, required this.repository});

  @override
  State<ShuttleSheet> createState() => _ShuttleSheetState();
}

class _ShuttleSheetState extends State<ShuttleSheet> {
  Timer? _ticker;
  String? _expanded;
  late Future<List<ShuttleRoute>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getShuttleRoutes();
    // Keep the countdowns fresh while the sheet is open.
    _ticker = Timer.periodic(const Duration(seconds: 30), (_) {
      if (mounted) setState(() {});
    });
  }

  @override
  void dispose() {
    _ticker?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final now = DateTime.now();
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 18, 20, 28),
      constraints:
          BoxConstraints(maxHeight: MediaQuery.of(context).size.height * .85),
      decoration: const BoxDecoration(
          color: Colors.white,
          borderRadius: BorderRadius.vertical(top: Radius.circular(28))),
      child: SafeArea(
        top: false,
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          Row(children: [
            const Icon(Icons.directions_bus_filled_outlined,
                color: ArucadColors.primary),
            const SizedBox(width: 10),
            const Expanded(
                child: Text('Servis Saatleri',
                    style:
                        TextStyle(fontWeight: FontWeight.w900, fontSize: 18))),
            IconButton(
                onPressed: () => Navigator.pop(context),
                icon: const Icon(Icons.close)),
          ]),
          const SizedBox(height: 12),
          Flexible(
            child: FutureBuilder<List<ShuttleRoute>>(
              future: _future,
              builder: (context, snap) {
                if (snap.connectionState != ConnectionState.done) {
                  return const Padding(
                    padding: EdgeInsets.symmetric(vertical: 24),
                    child: Center(child: CircularProgressIndicator()),
                  );
                }
                if (snap.hasError) {
                  return const Padding(
                    padding: EdgeInsets.symmetric(vertical: 24),
                    child: Text('Servis saatleri yüklenemedi.',
                        style: TextStyle(color: ArucadColors.muted)),
                  );
                }
                final routes = snap.data ?? const [];
                if (routes.isEmpty) {
                  return const Padding(
                    padding: EdgeInsets.symmetric(vertical: 24),
                    child: Text('Henüz servis hattı eklenmedi.',
                        style: TextStyle(color: ArucadColors.muted)),
                  );
                }
                return ListView.separated(
                  shrinkWrap: true,
                  itemCount: routes.length,
                  separatorBuilder: (_, __) => const SizedBox(height: 10),
                  itemBuilder: (context, index) {
                    final route = routes[index];
                    final expanded = _expanded == route.id;
                final outbound = nextDeparture(route.departures, now);
                final inbound = route.returns == null
                    ? null
                    : nextDeparture(route.returns!, now);

                return Card(
                  child: InkWell(
                    borderRadius: BorderRadius.circular(18),
                    onTap: () =>
                        setState(() => _expanded = expanded ? null : route.id),
                    child: Padding(
                      padding: const EdgeInsets.all(16),
                      child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Row(children: [
                              Container(
                                width: 12,
                                height: 12,
                                decoration: BoxDecoration(
                                    color: route.color, shape: BoxShape.circle),
                              ),
                              const SizedBox(width: 10),
                              Expanded(
                                  child: Text(route.name,
                                      style: const TextStyle(
                                          fontWeight: FontWeight.w800))),
                              Icon(expanded
                                  ? Icons.expand_less
                                  : Icons.expand_more),
                            ]),
                            const SizedBox(height: 8),
                            _DepartureLine(
                              label: inbound == null
                                  ? 'Sıradaki kalkış'
                                  : 'Kampüsten kalkış',
                              time: outbound.label,
                              countdown: formatCountdown(outbound.until),
                              color: route.color,
                            ),
                            if (inbound != null) ...[
                              const SizedBox(height: 4),
                              _DepartureLine(
                                label: 'Dönüş',
                                time: inbound.label,
                                countdown: formatCountdown(inbound.until),
                                color: route.color,
                              ),
                            ],
                            if (expanded) ...[
                              const SizedBox(height: 12),
                              const Divider(height: 1),
                              const SizedBox(height: 10),
                              for (final stop in route.stops)
                                Padding(
                                  padding: const EdgeInsets.only(bottom: 6),
                                  child: Row(children: [
                                    const Icon(Icons.place_outlined,
                                        size: 14, color: ArucadColors.muted),
                                    const SizedBox(width: 6),
                                    Expanded(
                                        child: Text(stop,
                                            style: const TextStyle(
                                                fontSize: 13,
                                                color: ArucadColors.muted))),
                                  ]),
                                ),
                            ],
                          ]),
                    ),
                  ),
                );
                  },
                );
              },
            ),
          ),
        ]),
      ),
    );
  }
}

class _DepartureLine extends StatelessWidget {
  final String label;
  final String time;
  final String countdown;
  final Color color;

  const _DepartureLine({
    required this.label,
    required this.time,
    required this.countdown,
    required this.color,
  });

  @override
  Widget build(BuildContext context) => Row(children: [
        Text('$label: ',
            style:
                const TextStyle(fontSize: 12, color: ArucadColors.muted)),
        Text(time,
            style: const TextStyle(
                fontWeight: FontWeight.w800, fontSize: 12)),
        const SizedBox(width: 6),
        Text('· $countdown',
            style: TextStyle(
                fontWeight: FontWeight.w800, fontSize: 12, color: color)),
      ]);
}
