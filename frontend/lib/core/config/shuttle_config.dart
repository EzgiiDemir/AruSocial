import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// A published ARUCAD shuttle line. Times come from the fixed timetable
/// posted each academic term (arucad.edu.tr / campus noticeboards) — this is
/// a schedule, not a live GPS feed, so "next departure" is computed from the
/// clock rather than an actual vehicle position.
class ShuttleRoute {
  final String id;
  final String name;

  /// One of `App\Models\ShuttleRoute::COLOR_KEYS` — a named ARUCAD chrome
  /// color, not a free hex value, so admins can't drift off the palette.
  final String colorKey;
  final List<String> stops;

  /// Departure times from campus, as 'HH:mm'.
  final List<String> departures;

  /// For lines with a distinct return leg (e.g. Bandabuliya), the times
  /// coming back to campus. Null when the line runs one continuous loop.
  final List<String>? returns;

  const ShuttleRoute({
    required this.id,
    required this.name,
    required this.colorKey,
    required this.stops,
    required this.departures,
    this.returns,
  });

  Color get color => colorForShuttleKey(colorKey);

  factory ShuttleRoute.fromJson(Map<String, dynamic> json) => ShuttleRoute(
        id: json['id'] as String,
        name: json['name'] as String,
        colorKey: json['colorKey'] as String? ?? 'blue',
        stops: (json['stops'] as List<dynamic>? ?? const []).cast<String>(),
        departures:
            (json['departures'] as List<dynamic>? ?? const []).cast<String>(),
        returns: (json['returns'] as List<dynamic>?)?.cast<String>(),
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'colorKey': colorKey,
        'stops': stops,
        'departures': departures,
        'returns': returns,
      };

  /// Mirrors `App\Models\ShuttleRoute::COLOR_KEYS` — the fixed set an admin
  /// can pick from, so the palette stays closed.
  static const knownColorKeys = [
    'blue',
    'yellow',
    'success',
    'warning',
    'campusGreen',
    'primary',
    'danger',
  ];

  static bool colorKeyIsKnown(String? key) => knownColorKeys.contains(key);
}

/// Maps the backend's `colorKey` (`App\Models\ShuttleRoute::COLOR_KEYS`) to
/// the actual ARUCAD chrome color — admins pick a named key, never a raw hex
/// value, so the palette stays closed.
Color colorForShuttleKey(String? key) => switch (key) {
      'yellow' => ArucadColors.yellow,
      'success' => ArucadColors.success,
      'warning' => ArucadColors.warning,
      'campusGreen' => ArucadColors.campusGreen,
      'primary' => ArucadColors.primary,
      'danger' => ArucadColors.danger,
      _ => ArucadColors.blue,
    };

const shuttleRoutes = <ShuttleRoute>[
  ShuttleRoute(
    id: 'nicosia',
    name: 'Lefkoşa Servisi',
    colorKey: 'blue',
    stops: [
      'ARUCAD Kyrenia Kampüsü',
      'Boğaz',
      'Gönyeli Kavşağı',
      'Lefkoşa Fuarı',
      'Honda',
      'Jet Gaz',
      'Macro',
      'Terminal',
      'Girne Kapısı',
      'Merit Hotel',
      'Hastane',
      'Gönyeli Kavşağı',
      'Boğaz',
      'ARUCAD Kyrenia Kampüsü',
    ],
    departures: ['07:00', '11:00', '13:00', '16:00', '18:00', '20:00'],
  ),
  ShuttleRoute(
    id: 'alsancak',
    name: 'Alsancak Servisi',
    colorKey: 'yellow',
    stops: [
      'ARUCAD Kyrenia Kampüsü',
      'British Cemetery',
      'Fountain Roundabout',
      'Nusmar Market',
      'Bakır Apart',
      'Uzun Petrol',
      'Sharaf',
      'Starling',
      'Merit Hotel Işıkları',
      'Dima Supermarket',
      'Kervansaray',
      'China Bazaar',
      'Edremit (Karaoğlanoğlu)',
      'Macro (Karaoğlanoğlu)',
      'Kaşgar',
      'Barış Park',
      'Girne Belediyesi',
      'ARUCAD Kyrenia Kampüsü',
    ],
    departures: ['08:00', '10:00', '12:00', '14:00', '16:00', '19:00'],
  ),
  ShuttleRoute(
    id: 'catalkoy',
    name: 'Çatalköy Servisi',
    colorKey: 'success',
    stops: [
      'ARUCAD Kyrenia Kampüsü',
      'Mahkemeler',
      'Atölyeler Binası',
      'Kibet',
      'Giralı Fırın',
      'Metropol Market',
      'Hankor Motor',
      'Barkot Market',
      'Çin Pazarı',
      'Dima Supermarket',
      'Çoban Trading',
      'Şah Market',
      'Supreme Supermarket',
      'Tempo Super Market',
    ],
    departures: ['08:00', '10:00', '12:00', '14:00', '16:00', '18:00'],
  ),
  ShuttleRoute(
    id: 'bandabuliya',
    name: 'Bandabuliya Servisi',
    colorKey: 'warning',
    stops: ['ARUCAD Kyrenia Kampüsü', 'Bandabuliya'],
    departures: ['07:30', '10:00', '15:00'],
    returns: ['09:00', '13:00', '18:00'],
  ),
  ShuttleRoute(
    id: 'iris',
    name: 'Atölye Binası (Iris) Servisi',
    colorKey: 'campusGreen',
    stops: [
      'ARUCAD Kyrenia Kampüsü',
      'Mahkemeler',
      'Akçiçek Hastanesi',
      'ARUCAD Atölye Binası (Iris)',
    ],
    departures: ['08:00', '10:00', '12:00', '14:00', '16:00', '18:00'],
  ),
];

/// The next scheduled time in [times] after [now], and how long until then.
/// Wraps to the first departure the following day once today's times have
/// all passed.
({String label, Duration until}) nextDeparture(List<String> times, DateTime now) {
  final today = DateTime(now.year, now.month, now.day);
  final parsed = times.map((time) {
    final parts = time.split(':');
    return today.add(
        Duration(hours: int.parse(parts[0]), minutes: int.parse(parts[1])));
  }).toList()
    ..sort();

  for (final dt in parsed) {
    if (dt.isAfter(now)) {
      return (label: _formatTime(dt), until: dt.difference(now));
    }
  }
  final tomorrow = parsed.first.add(const Duration(days: 1));
  return (label: _formatTime(tomorrow), until: tomorrow.difference(now));
}

String _formatTime(DateTime dt) =>
    '${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';

String formatCountdown(Duration d) {
  if (d.inMinutes < 1) return 'şimdi';
  if (d.inMinutes < 60) return '${d.inMinutes} dk sonra';
  final hours = d.inMinutes ~/ 60;
  final minutes = d.inMinutes % 60;
  return minutes == 0 ? '$hours sa sonra' : '$hours sa $minutes dk sonra';
}

/// The nearest upcoming departure across every line — used both for the map
/// toolbar's quick countdown and for Galatea's shuttle answers.
({ShuttleRoute route, Duration until}) soonestDeparture() {
  final now = DateTime.now();
  ShuttleRoute? best;
  Duration? bestUntil;
  for (final route in shuttleRoutes) {
    final schedules = <List<String>>[
      route.departures,
      if (route.returns != null) route.returns!,
    ];
    for (final times in schedules) {
      final next = nextDeparture(times, now);
      if (bestUntil == null || next.until < bestUntil) {
        best = route;
        bestUntil = next.until;
      }
    }
  }
  return (route: best!, until: bestUntil!);
}
