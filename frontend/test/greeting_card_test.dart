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

  test('all 14 daily quotes are translated and have an author', () {
    for (var number = 1; number <= GreetingCard.quoteCount; number++) {
      final at = DateTime(2026, 9, 20 + number);
      expect(GreetingCard.quoteNumberFor(at), number);

      final key = GreetingCard.quoteKeyFor(at);
      for (final language in AppLanguage.values) {
        final quote = AppStrings(language).t(key);
        expect(quote, isNot(key),
            reason: '$key has no ${language.name} translation');
        expect(quote.trim(), isNotEmpty);
      }
      expect(GreetingCard.quoteAuthorFor(at), isNotEmpty);
    }

    expect(GreetingCard.quoteNumberFor(DateTime(2026, 10, 5)), 1,
        reason: 'the collection should loop after day 14');
  });

  test('only the first name is greeted', () {
    expect(GreetingCard.firstName('Ezgi Demir'), 'Ezgi');
    expect(GreetingCard.firstName('Ezgi'), 'Ezgi');
    expect(GreetingCard.firstName('  Ezgi  Demir '), 'Ezgi');
    expect(GreetingCard.firstName(''), '');
  });

  testWidgets('renders the greeting, daily quote, author and weather',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: AppLocale(
        language: AppLanguage.tr,
        child: Scaffold(
          body: GreetingCard(
            userName: 'Ezgi Demir',
            now: DateTime(2026, 9, 21, 14, 0), // quote 1, afternoon
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
    expect(
        find.textContaining('Sadece hayatını yaşamak yetmez'), findsOneWidget);
    expect(find.text('— Marcus Aurelius'), findsOneWidget);
    expect(find.textContaining('Girne'), findsOneWidget);
    expect(find.textContaining('27°C'), findsOneWidget);

    final card = tester.getRect(find.byType(GreetingCard));
    final weather = tester.getRect(find.textContaining('Girne'));
    expect(weather.center.dx, greaterThan(card.center.dx));
  });

  testWidgets('uses the selected language and fits the longest quote on phone',
      (tester) async {
    await tester.binding.setSurfaceSize(const Size(360, 800));
    addTearDown(() => tester.binding.setSurfaceSize(null));

    await tester.pumpWidget(MaterialApp(
      home: AppLocale(
        language: AppLanguage.ru,
        child: Scaffold(
          body: GreetingCard(
            userName: 'Ezgi',
            now: DateTime(2026, 10, 3, 10), // quote 13
            weather: const CampusWeather(
              temperatureC: 29,
              feelsLikeC: 29,
              windKph: 8,
              code: 2,
              summary: 'Переменная облачность',
              isDay: true,
            ),
          ),
        ),
      ),
    ));

    expect(find.textContaining('Легко простить ребенка'), findsOneWidget);
    expect(find.text('— Platon'), findsOneWidget);
    // 309, up from 290: the quote moved onto a padded panel (+19). It is not
    // larger than that because the quote and the greeting are now both bounded
    // by maxLines, so the frame no longer has to be sized against whatever the
    // longest quote happens to wrap to. Sizing it that way meant tuning one
    // number against two conflicting constraints — the card's own content and
    // the home shell around it — with only a few pixels between them, and it
    // overflowed the shell by 121px on a 360px phone before this was caught.
    expect(tester.getSize(find.byType(GreetingCard)).height, 309);
    expect(tester.takeException(), isNull);
  });

  testWidgets('a long name is never broken mid-word by the weather chip',
      (tester) async {
    // 360px is a Galaxy S24. The chip used to claim enough of this row that
    // "Günaydın, Test" rendered as "Günaydı / n, Test" — a name split inside
    // a word, which reads as a rendering fault rather than a greeting.
    //
    // The width is imposed with a SizedBox rather than setSurfaceSize, which
    // does not move MediaQuery in a widget test: on a surface set to 360 it
    // still reports 800, so a width assertion made that way tests nothing.
    await tester.pumpWidget(MaterialApp(
      home: AppLocale(
        language: AppLanguage.tr,
        child: Scaffold(
          body: SizedBox(
            width: 360,
            child: GreetingCard(
              userName: 'Abdurrahman',
              now: DateTime(2026, 9, 29, 9),
              weather: const CampusWeather(
                temperatureC: 27,
                feelsLikeC: 27,
                windKph: 8,
                code: 3,
                summary: 'Bulutlu',
                isDay: true,
              ),
            ),
          ),
        ),
      ),
    ));

    // The greeting renders whole.
    expect(find.text('Günaydın, Abdurrahman'), findsOneWidget);
    // And the chip has stepped back to the temperature alone to allow it.
    expect(find.textContaining('27°C'), findsOneWidget);
    expect(find.textContaining('Girne'), findsNothing);
    expect(tester.takeException(), isNull);
  });

  testWidgets('the full forecast is shown when the row can hold it',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: AppLocale(
        language: AppLanguage.tr,
        child: Scaffold(
          body: SizedBox(
            width: 820,
            child: GreetingCard(
              userName: 'Test',
              now: DateTime(2026, 9, 29, 9),
              weather: const CampusWeather(
                temperatureC: 27,
                feelsLikeC: 27,
                windKph: 8,
                code: 3,
                summary: 'Bulutlu',
                isDay: true,
              ),
            ),
          ),
        ),
      ),
    ));

    expect(find.textContaining('Girne'), findsOneWidget);
    expect(find.textContaining('Bulutlu'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  testWidgets('keeps weather visible with an honest unavailable state',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: AppLocale(
        language: AppLanguage.tr,
        child: const Scaffold(
          body: GreetingCard(userName: 'Ezgi'),
        ),
      ),
    ));

    expect(find.textContaining('Girne'), findsOneWidget);
    expect(find.textContaining('geçici olarak alınamıyor'), findsOneWidget);
    expect(find.textContaining('°C'), findsNothing);
  });
}
