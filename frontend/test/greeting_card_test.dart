import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_weather.dart';
import 'package:arucad_campus_prototype/features/home/greeting_card.dart';

/// The greeting is time-of-day logic with off-by-one boundaries at 05:00,
/// 12:00, 18:00 and 22:00 — exactly the kind of thing that is only ever
/// noticed by a student being told "good night" at lunchtime.
void main() {
  group('greeting by hour', () {
    final cases = <int, String>{
      0: 'greet_night',
      4: 'greet_night',
      5: 'greet_morning',
      11: 'greet_morning',
      12: 'greet_day',
      17: 'greet_day',
      18: 'greet_evening',
      21: 'greet_evening',
      22: 'greet_night',
      23: 'greet_night',
    };

    test('each boundary lands in the right band', () {
      cases.forEach((hour, expected) {
        final at = DateTime(2026, 9, 8, hour, 30);
        expect(GreetingCard.greetingKeyFor(at), expected,
            reason: '$hour:30 should be $expected');
      });
    });
  });

  test('a line exists for every weekday and is translated everywhere', () {
    for (var weekday = 1; weekday <= 7; weekday++) {
      // 2026-09-07 is a Monday, so this walks Mon..Sun.
      final at = DateTime(2026, 9, 6 + weekday);
      expect(at.weekday, weekday);

      final key = GreetingCard.lineKeyFor(at);
      for (final language in AppLanguage.values) {
        final line = AppStrings(language).t(key);
        expect(line, isNot(key),
            reason: '$key has no ${language.name} translation');
        expect(line.trim(), isNotEmpty);
      }
    }
  });

  test('only the first name is greeted', () {
    expect(GreetingCard.firstName('Ezgi Demir'), 'Ezgi');
    expect(GreetingCard.firstName('Ezgi'), 'Ezgi');
    expect(GreetingCard.firstName('  Ezgi  Demir '), 'Ezgi');
    expect(GreetingCard.firstName(''), '');
  });

  testWidgets('renders the greeting, the day line and the weather', (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: AppLocale(
        language: AppLanguage.tr,
        child: Scaffold(
          body: GreetingCard(
            userName: 'Ezgi Demir',
            now: DateTime(2026, 9, 8, 14, 0), // Tuesday afternoon
            weather: const CampusWeather(
              temperatureC: 27,
              feelsLikeC: 28,
              windKph: 9,
              code: 2,
              summary: 'Parçalı bulutlu',
              isDay: true,
            ),
          ),
        ),
      ),
    ));

    expect(find.text('İyi günler, Ezgi'), findsOneWidget);
    expect(find.textContaining('Girne'), findsOneWidget);
    expect(find.textContaining('27°C'), findsOneWidget);
  });

  testWidgets('omits the weather row rather than inventing a temperature',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: AppLocale(
        language: AppLanguage.tr,
        child: const Scaffold(
          body: GreetingCard(userName: 'Ezgi'),
        ),
      ),
    ));

    expect(find.textContaining('Girne'), findsNothing);
    expect(find.textContaining('°C'), findsNothing);
  });
}
