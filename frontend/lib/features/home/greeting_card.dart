import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_weather.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// The card that opens Home: time-aware greeting, a line for the day, and
/// the current campus weather over a photo of the ARUCAD courtyard.
///
/// It replaced a "Kampüs anketleri" tile, which asked something of the
/// student before the app had said anything to them. Surveys still appear —
/// as a prompt when there is genuinely one waiting — but they are no longer
/// the first thing on the screen.
///
/// Everything shown is real: the name is the signed-in user's, the weather
/// comes from the backend, and when the weather is unavailable that row is
/// simply absent rather than showing a placeholder temperature.
class GreetingCard extends StatelessWidget {
  final String userName;
  final CampusWeather? weather;

  /// Injectable so the greeting can be tested without waiting for 6am.
  final DateTime? now;

  const GreetingCard({
    super.key,
    required this.userName,
    this.weather,
    this.now,
  });

  /// 05:00–11:59 morning · 12:00–17:59 day · 18:00–21:59 evening · rest night
  static String greetingKeyFor(DateTime at) {
    final h = at.hour;
    if (h >= 5 && h < 12) return 'greet_morning';
    if (h >= 12 && h < 18) return 'greet_day';
    if (h >= 18 && h < 22) return 'greet_evening';

    return 'greet_night';
  }

  /// One line per weekday, so the card changes daily without ever feeling
  /// random — a new sentence on every rebuild would read as noise.
  static String lineKeyFor(DateTime at) => 'greet_line_${at.weekday}';

  /// The first name only. "Günaydın, Ezgi" reads like a person talking;
  /// the full legal name reads like a form.
  static String firstName(String full) {
    final trimmed = full.trim();
    if (trimmed.isEmpty) return '';
    final space = trimmed.indexOf(' ');

    return space < 0 ? trimmed : trimmed.substring(0, space);
  }

  /// Real weather icons rather than emoji, so the card matches the rest of
  /// the app's iconography and scales with text size.
  static IconData iconFor(CampusWeather? weather, DateTime at) {
    if (weather == null) {
      final night = at.hour >= 22 || at.hour < 5;

      return night ? Icons.nightlight_round : Icons.wb_sunny_rounded;
    }
    // WMO codes: 0 clear, 1–3 cloud, 45/48 fog, 51–67 drizzle/rain,
    // 71–77 snow, 80–82 showers, 95–99 thunder.
    return switch (weather.code) {
      0 => weather.isDay ? Icons.wb_sunny_rounded : Icons.nightlight_round,
      1 || 2 => Icons.wb_cloudy_outlined,
      3 => Icons.cloud_rounded,
      45 || 48 => Icons.foggy,
      >= 51 && <= 67 => Icons.grain_rounded,
      >= 71 && <= 77 => Icons.ac_unit_rounded,
      >= 80 && <= 82 => Icons.water_drop_rounded,
      >= 95 => Icons.thunderstorm_rounded,
      _ => Icons.wb_cloudy_outlined,
    };
  }

  /// Darker overlay at night and in bad weather so white text stays legible
  /// against whatever the photo is doing behind it.
  List<Color> _scrim(DateTime at) {
    final night = at.hour >= 22 || at.hour < 5;
    final dim = night || (weather != null && weather!.code >= 51);

    return dim
        ? [const Color(0xCC0A1330), const Color(0x660A1330)]
        : [const Color(0x990A1330), const Color(0x33000000)];
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final at = now ?? DateTime.now();
    final name = firstName(userName);
    final greeting = strings.t(greetingKeyFor(at));

    return ClipRRect(
      borderRadius: BorderRadius.circular(20),
      child: Stack(children: [
        Positioned.fill(
          child: Image.asset(
            'assets/images/campus-courtyard.jpg',
            fit: BoxFit.cover,
            // Drop the courtyard photo in at that path and it appears here.
            // Until then the card falls back to a brand gradient rather
            // than a broken-image box, so it looks deliberate either way.
            errorBuilder: (_, __, ___) => const DecoratedBox(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                  colors: [Color(0xFF1B2A6B), ArucadColors.primary],
                ),
              ),
            ),
          ),
        ),
        Positioned.fill(
          child: DecoratedBox(
            decoration: BoxDecoration(
              gradient: LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: _scrim(at),
              ),
            ),
          ),
        ),
        Padding(
          padding: const EdgeInsets.fromLTRB(20, 22, 20, 18),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisSize: MainAxisSize.min,
            children: [
              Text(
                name.isEmpty ? greeting : '$greeting, $name',
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 26,
                  height: 1.15,
                  fontWeight: FontWeight.w900,
                ),
              ),
              const SizedBox(height: 6),
              Text(
                strings.t(lineKeyFor(at)),
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 13.5,
                  height: 1.4,
                ),
              ),
              const SizedBox(height: 18),
              if (weather != null)
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                  decoration: BoxDecoration(
                    color: Colors.white.withValues(alpha: .18),
                    borderRadius: BorderRadius.circular(999),
                    border: Border.all(
                        color: Colors.white.withValues(alpha: .35)),
                  ),
                  child: Row(mainAxisSize: MainAxisSize.min, children: [
                    Icon(iconFor(weather, at), size: 17, color: Colors.white),
                    const SizedBox(width: 8),
                    Text(
                      'Girne · ${weather!.summary} · '
                      '${weather!.temperatureC.round()}°C',
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 12.5,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                  ]),
                ),
            ],
          ),
        ),
      ]),
    );
  }
}
