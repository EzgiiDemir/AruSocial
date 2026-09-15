import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/features/campus_shell.dart';
import 'package:arucad_campus_prototype/features/widgets/arucad_line_icon.dart';
import 'package:arucad_campus_prototype/features/explore/explore_screen.dart';
import 'package:arucad_campus_prototype/features/home/home_screen.dart';

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

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  testWidgets('CampusShell has 5 root tabs including Ask ARUCAD',
      (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: CampusShell(
        user: const CampusUser(
          id: 'u1',
          name: 'Test',
          role: 'student',
          level: 1,
          xp: 0,
          places: 0,
          events: 0,
          memories: 0,
          interests: [],
        ),
        repository: MockCampusRepository(),
        mapProvider: _NoopMap(),
        analyticsTracker: _NoopAnalytics(),
        onLogout: () {},
      ),
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));

    expect(find.byType(NavigationBar), findsOneWidget);
    expect(find.byType(NavigationDestination), findsNWidgets(5));
    expect(find.byType(ArucadLineIcon), findsNWidgets(5));
    // Taken from the string table rather than typed in. The tab was
    // renamed to "Aicad" and this assertion still looked for the old
    // "Arucad'a Sor", so it failed on a rename that broke nothing —
    // noise that trains people to ignore a red suite.
    expect(
      find.textContaining(const AppStrings(AppLanguage.tr).t('nav_ask')),
      findsOneWidget,
    );
    // Offstage tabs stay unbuilt so login does not stampede php artisan serve.
    expect(find.byType(ExploreScreen), findsNothing);
    expect(find.byType(HomeScreen), findsOneWidget);
  });
}
