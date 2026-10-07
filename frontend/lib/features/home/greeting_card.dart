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
/// comes from the backend, and when the weather is unavailable the card says
/// so explicitly rather than silently disappearing or inventing a value.
class GreetingCard extends StatelessWidget {
  final String userName;
  final CampusWeather? weather;
  final bool weatherLoading;

  /// Injectable so the greeting can be tested without waiting for 6am.
  final DateTime? now;

  const GreetingCard({
    super.key,
    required this.userName,
    this.weather,
    this.weatherLoading = false,
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

  static const int quoteCount = 14;

  /// The supplied collection starts on 21 September 2026 and advances once
  /// per local calendar day. UTC date-only values avoid daylight-saving
  /// transitions turning a local day into 23 or 25 hours.
  static int quoteNumberFor(DateTime at) {
    final day = DateTime.utc(at.year, at.month, at.day);
    final firstDay = DateTime.utc(2026, 9, 21);
    final elapsedDays = day.difference(firstDay).inDays;

    return ((elapsedDays % quoteCount) + quoteCount) % quoteCount + 1;
  }

  static String quoteKeyFor(DateTime at) => 'greet_quote_${quoteNumberFor(at)}';

  static String quoteAuthorFor(DateTime at) => switch (quoteNumberFor(at)) {
        1 || 8 => 'Marcus Aurelius',
        2 => 'William Shakespeare',
        3 => 'Fyodor Dostoyevski',
        4 || 5 => 'Sokrates',
        6 => 'Eleanor Roosevelt',
        7 => 'Epiktetos',
        9 => 'Friedrich Nietzsche',
        10 || 14 => 'Mahatma Gandhi',
        11 => 'Alan Kay',
        12 => 'Francis Bacon',
        13 => 'Platon',
        _ => '',
      };

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
      return [const Color(0xC20A1330), const Color(0x800A1330)];
    }
    if (storm || raining) {
      return [const Color(0xAD1F2A3A), const Color(0x7A1F2A3A)];
    }
    if (hour >= 18) {
      // Sunset: warm at the top, deepening towards the text.
      return [const Color(0xAD3A2352), const Color(0x7A12203F)];
    }
    if (code >= 1 && code <= 48) {
      // Cloud or fog — the photo is flatter, so it needs less help.
      return [const Color(0x99122040), const Color(0x6B122040)];
    }

    // Clear day: the brightest the photo ever is, so the heaviest scrim.
    return [const Color(0xA60A1330), const Color(0x6B0A1330)];
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocale.of(context);
    final at = now ?? DateTime.now();
    final name = firstName(userName);
    final greeting = strings.t(greetingKeyFor(at));
    final quote = strings.t(quoteKeyFor(at));
    final quoteAuthor = quoteAuthorFor(at);

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
    // Quotes of ordinary length can still wrap to five lines at the supported
    // 1.3x cap on a narrow phone. Keep enough baseline room for that real
    // accessibility case; the longest supplied quote receives a little more.
    final needsLongQuoteRoom = quoteNumberFor(at) == 13;
    final scale = scaler.scale(1.0);
    // The weather chip now shares the top row with the greeting instead of
    // occupying its own line at the bottom, so the card no longer needs a
    // whole row of height for it. What it does need is room for the greeting
    // to wrap beside it: the chip takes up to 190px, and at the 1.3x
    // accessibility cap "Günaydın, Ezgi" no longer fits on one line next to
    // that.
    final wrappedGreetingRoom = 34 * ((scale - 1) / .3).clamp(0.0, 1.0);
    // The quote sits on a padded panel now rather than as bare text. That
    // costs height twice over, and budgeting only the first half still
    // overflowed: 19px of vertical padding, plus the 26px of side padding
    // narrowing the text column so the longest quote wraps to roughly two
    // more lines. Measured at 1.3x: 52px short with neither, 33px short with
    // only the padding counted.
    //
    // Split, because the two halves do not apply equally. The padding is
    // always there; the extra wrapping only bites on a quote long enough to
    // reach the narrowed edge, which is the one the `needsLongQuoteRoom` flag
    // already identifies. Charging every quote for it made the card 52px
    // taller than it needed to be and overflowed the home shell by 70px on a
    // 360px phone.
    // Only the panel's own padding. The quote itself is bounded by maxLines
    // below, so the frame no longer has to be sized against however long the
    // longest quote happens to wrap — which was a fixed height tuned against
    // two conflicting constraints at once (the card's own content, and the
    // home shell it sits in) with a window only a few pixels wide between
    // them.
    const quotePanelRoom = 19.0;
    // There is no longer a "weather unavailable" row to make room for: the
    // chip sits inline with the greeting and is the same pill whether it shows
    // a temperature or says the reading is unavailable.
    final height = (needsLongQuoteRoom ? 290 : 260) * scale +
        wrappedGreetingRoom +
        quotePanelRoom * scale;

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
                // Measures the space the card actually has, which is what
                // decides whether the weather chip can afford its long form.
                // MediaQuery is the wrong signal here twice over: the card is
                // not always the full screen width, and in a widget test
                // `setSurfaceSize` does not move it at all — it reports 800
                // on a surface set to 360, so a screen-width rule silently
                // never fired and the test that was meant to catch a
                // mid-word break could not have caught one.
                child: LayoutBuilder(builder: (context, constraints) {
                  final compact = constraints.maxWidth < 360;

                  return Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.max,
                    children: [
                      // Greeting on the left, weather on the right, on one row.
                      //
                      // The weather used to sit alone at the bottom-right, which
                      // put the two things a student glances at — who they are
                      // and what it is doing outside — at opposite corners of a
                      // photograph. Side by side they read as one line.
                      Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Expanded(
                            child: Text(
                              name.isEmpty ? greeting : '$greeting, $name',
                              // Two lines at most. Sharing the row with the
                              // weather leaves the greeting a narrower column
                              // than it had to itself, and unbounded it wrapped
                              // to three lines on a 360px phone — which is what
                              // overflowed the card by 121px.
                              maxLines: 2,
                              overflow: TextOverflow.ellipsis,
                              // Without this a narrow column breaks inside a
                              // word rather than between words.
                              softWrap: true,
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 26,
                                height: 1.15,
                                fontWeight: FontWeight.w900,
                              ),
                            ),
                          ),
                          const SizedBox(width: 10),
                          // The chip gives way to the name, and never the other
                          // way round.
                          //
                          // Even flexible, a chip holding "Girne · Bulutlu ·
                          // 27°C" claimed enough of a 360px row that "Günaydın,
                          // Test" broke MID-WORD — "Günaydı / n, Test". A
                          // forecast losing its city label is a small loss; a
                          // student's name split across two lines is not.
                          // _weatherChip drops to icon + temperature when the
                          // row is tight.
                          Flexible(
                            flex: 0,
                            child: _weatherChip(strings, at, compact: compact),
                          ),
                        ],
                      ),
                      const Spacer(),
                      // The quote, bottom-left, on the same smoky panel as the
                      // weather so the two read as one family rather than two
                      // unrelated treatments.
                      Align(
                        alignment: Alignment.bottomLeft,
                        child: _SmokyPanel(
                          radius: 14,
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Text(
                                '“$quote”',
                                // Bounded, so the quote can never push the card
                                // past its frame. Four lines holds every supplied
                                // quote at 1x and the great majority at the 1.3x
                                // accessibility cap; beyond that an ellipsis is a
                                // better outcome than a clipped card, which is the
                                // same trade the bottom nav bar already makes.
                                maxLines: 4,
                                overflow: TextOverflow.ellipsis,
                                style: const TextStyle(
                                  color: Colors.white,
                                  fontSize: 12.5,
                                  height: 1.3,
                                  fontWeight: FontWeight.w500,
                                ),
                              ),
                              if (quoteAuthor.isNotEmpty) ...[
                                const SizedBox(height: 3),
                                Text(
                                  '— $quoteAuthor',
                                  style: TextStyle(
                                    color: Colors.white.withValues(alpha: .88),
                                    fontSize: 11.5,
                                    height: 1.2,
                                    fontWeight: FontWeight.w700,
                                  ),
                                ),
                              ],
                            ],
                          ),
                        ),
                      ),
                    ],
                  );
                }),
              ),
            ),
          ]),
        ),
      ),
    );
  }

  /// The weather pill, top-right beside the greeting.
  /// [compact] when the row cannot afford the long form: the chip then shows
  /// the temperature alone. The icon already says what the sky is doing, and
  /// "Girne" is not news to someone standing in it.
  Widget _weatherChip(AppStrings strings, DateTime at,
      {required bool compact}) {
    final text = weatherLoading
        ? strings.t('home_weather_loading')
        : weather == null
            ? strings.t('home_weather_unavailable')
            : compact
                ? '${weather!.temperatureC.round()}°C'
                : 'Girne · ${weather!.summary} · ${weather!.temperatureC.round()}°C';

    return _SmokyPanel(
      radius: 999,
      padding: EdgeInsets.symmetric(horizontal: compact ? 10 : 12, vertical: 7),
      child: ConstrainedBox(
        constraints: BoxConstraints(maxWidth: compact ? 96 : 170),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          if (weatherLoading)
            const SizedBox(
              width: 16,
              height: 16,
              child: CircularProgressIndicator(
                strokeWidth: 2,
                color: Colors.white,
              ),
            )
          else
            Icon(iconFor(weather, at), size: 17, color: Colors.white),
          SizedBox(width: compact ? 5 : 8),
          Flexible(
            child: Text(
              text,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: Colors.white,
                fontSize: compact ? 12 : 12.5,
                fontWeight: FontWeight.w700,
              ),
            ),
          ),
        ]),
      ),
    );
  }
}

/// A soft smoky panel for text sitting on the photograph.
///
/// The weather pill used to be a white wash at 18% opacity, which lightened
/// the area under white text instead of darkening it — the one thing it must
/// not do. Smoke is a dark tint with a faint light hairline: it lifts contrast
/// under the text while still letting the courtyard through, so the card still
/// reads as a photograph rather than a panel with a picture behind it.
///
/// Shared by the weather chip and the daily quote so the two look related.
class _SmokyPanel extends StatelessWidget {
  final Widget child;
  final double radius;
  final EdgeInsets padding;

  const _SmokyPanel({
    required this.child,
    required this.radius,
    this.padding = const EdgeInsets.fromLTRB(12, 9, 14, 10),
  });

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        // Dark, and deliberately not opaque. Past roughly 0.30 the photo stops
        // showing through and the card reads as a grey box.
        color: const Color(0xFF0A1330).withValues(alpha: .26),
        borderRadius: BorderRadius.circular(radius),
        border: Border.all(color: Colors.white.withValues(alpha: .16)),
      ),
      child: Padding(padding: padding, child: child),
    );
  }
}
