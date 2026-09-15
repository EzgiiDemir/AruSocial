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

  /// The photo is a single fixed image of the ARUCAD courtyard, so the time
  /// of day and the weather are expressed by tinting it rather than by
  /// swapping in six separate photographs nobody has taken.
  ///
  /// The tint also does real work: white text has to stay legible over a
  /// bright sunlit courtyard, so the overlay is heavier where the photo is
  /// lightest. Night gets a deep blue, dusk a warm amber, rain a desaturated
  /// grey-blue.
  List<Color> _scrim(DateTime at) {
    final hour = at.hour;
    final code = weather?.code ?? 0;
    final raining = code >= 51 && code <= 82;
    final storm = code >= 95;

    if (hour >= 22 || hour < 5) {
      return [const Color(0xB30A1330), const Color(0x660A1330)];
    }
    if (storm || raining) {
      return [const Color(0x991F2A3A), const Color(0x591F2A3A)];
    }
    if (hour >= 18) {
      // Sunset: warm at the top, deepening towards the text.
      return [const Color(0x993A2352), const Color(0x5912203F)];
    }
    if (code >= 1 && code <= 48) {
      // Cloud or fog — the photo is flatter, so it needs less help.
      return [const Color(0x80122040), const Color(0x40122040)];
    }

    // Clear day: the brightest the photo ever is, so the heaviest scrim.
    return [const Color(0x8C0A1330), const Color(0x400A1330)];
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final at = now ?? DateTime.now();
    final name = firstName(userName);
    final greeting = strings.t(greetingKeyFor(at));

    // The card is a fixed-height hero over a photograph, so its content
    // cannot push it taller — but every label inside scales with the
    // system font setting, and at 2x the column overflowed the 210px box
    // by 272 pixels.
    //
    // Capped rather than redesigned, the same trade the bottom nav bar
    // already makes for the same reason (see `_RootNavigationBar`): text
    // that grows without bound inside a fixed frame does not help anyone,
    // because it gets clipped. The cap applies to this card only — the
    // rest of the app honours the setting in full.
    final scaler = MediaQuery.textScalerOf(context)
        .clamp(minScaleFactor: 1.0, maxScaleFactor: 1.3);

    // And the frame grows with the text it holds. Capping alone still
    // overflowed by 34px, because 210 was only ever right for 1x — the
    // photo simply crops a little more, which is a far better outcome
    // than clipping the greeting.
    final height = 210 * scaler.scale(1.0);

    return MediaQuery(
      data: MediaQuery.of(context).copyWith(textScaler: scaler),
      child: SizedBox(
        height: height,
        child: ClipRRect(
        borderRadius: BorderRadius.circular(20),
        child: Stack(children: [
          Positioned.fill(
            child: Image.asset(
              'assets/images/arkin-yaratici-sanatlar-ve-tasarim-universitesi.jpg',
              fit: BoxFit.cover,
              // Falls back to a brand gradient rather than a broken-image box,
              // so a missing asset still looks deliberate.
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
          Positioned.fill(
            child: Padding(
              padding: const EdgeInsets.fromLTRB(20, 26, 20, 20),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.max,
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
                  const Spacer(),
                  if (weather != null)
                    Align(
                      alignment: Alignment.bottomRight,
                      child: Container(
                        padding: const EdgeInsets.symmetric(
                            horizontal: 12, vertical: 8),
                        decoration: BoxDecoration(
                          color: Colors.white.withValues(alpha: .18),
                          borderRadius: BorderRadius.circular(999),
                          border: Border.all(
                              color: Colors.white.withValues(alpha: .35)),
                        ),
                        child: Row(mainAxisSize: MainAxisSize.min, children: [
                          Icon(iconFor(weather, at),
                              size: 17, color: Colors.white),
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
                    ),
                  ],
                ),
              ),
            ),
          ]),
        ),
      ),
    );
  }
}
