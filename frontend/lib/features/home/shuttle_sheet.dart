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
  String? _expanded;
  late Future<List<ShuttleRoute>> _future;

  @override
  void initState() {
    super.initState();
    _future = widget.repository.getShuttleRoutes();
  }

  @override
  Widget build(BuildContext context) {
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
                child: Text('Servis Güzergâhları',
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
                    child: Text('Servis güzergâhları yüklenemedi.',
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
                    return Card(
                      child: InkWell(
                        borderRadius: BorderRadius.circular(18),
                        onTap: () => setState(
                            () => _expanded = expanded ? null : route.id),
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
                                        color: route.color,
                                        shape: BoxShape.circle),
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
                                Text(route.stops.join(' → '),
                                    style: const TextStyle(
                                        fontSize: 12,
                                        color: ArucadColors.muted,
                                        height: 1.35)),
                                if (expanded) ...[
                                  const SizedBox(height: 12),
                                  const Divider(height: 1),
                                  const SizedBox(height: 10),
                                  for (final stop in route.stops)
                                    Padding(
                                      padding: const EdgeInsets.only(bottom: 6),
                                      child: Row(children: [
                                        const Icon(Icons.place_outlined,
                                            size: 14,
                                            color: ArucadColors.muted),
                                        const SizedBox(width: 6),
                                        Expanded(
                                            child: Text(stop,
                                                style: const TextStyle(
                                                    fontSize: 13,
                                                    color:
                                                        ArucadColors.muted))),
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
