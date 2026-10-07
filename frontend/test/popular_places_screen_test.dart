import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/features/explore/popular_places_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() => SharedPreferences.setMockInitialValues({}));

  testWidgets('popular places uses a white search field and one filter button',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: AppLocale(
        language: AppLanguage.en,
        child: PopularPlacesScreen(
          repository: MockCampusRepository(),
          mapProvider: _NoopMap(),
          analyticsTracker: _NoopAnalytics(),
        ),
      ),
    ));
    await tester.pump();
    for (var i = 0; i < 20 && find.byType(TextField).evaluate().isEmpty; i++) {
      await tester.pump(const Duration(milliseconds: 100));
    }

    final field = tester.widget<TextField>(find.byType(TextField));
    expect(field.decoration?.fillColor, Colors.white);
    expect(find.byIcon(Icons.filter_list_rounded), findsOneWidget);
    expect(find.byType(SelectableChip), findsNothing);

    await tester.tap(find.byIcon(Icons.filter_list_rounded));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));
    expect(find.text('Category filter'), findsOneWidget);
    expect(find.text('All'), findsOneWidget);
  });
}

class _NoopMap implements MapProvider {
  @override
  Future<void> startRoute(
      {required String destination, bool accessibleOnly = false}) async {}

  @override
  Future<void> openTour(String tourUrl) async {}
}

class _NoopAnalytics implements AnalyticsTracker {
  @override
  void track(String event, [Map<String, Object?> properties = const {}]) {}
}
