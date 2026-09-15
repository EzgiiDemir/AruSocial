import 'dart:io';

import 'package:flutter_test/flutter_test.dart';

void main() {
  test('Flutter entry point exposes only the student application', () {
    final main = File('lib/main.dart').readAsStringSync();
    final app = File('lib/app/app.dart').readAsStringSync();
    final profile = File('lib/features/profile/profile_screen.dart')
        .readAsStringSync();
    final webUrl = File('lib/core/platform/url_strategy_web.dart')
        .readAsStringSync();

    expect(main, isNot(contains('startInAdminMode')));
    expect(main, isNot(contains('startInTrainerMode')));
    expect(app, isNot(contains('AdminPanelScreen(')));
    expect(app, isNot(contains('TrainerPanelScreen(')));
    expect(profile, isNot(contains('AdminPanelScreen(')));
    expect(profile, isNot(contains('TrainerPanelScreen(')));
    expect(webUrl, contains("path == '/admin'"));
    expect(webUrl, contains("path == '/trainer'"));
    expect(webUrl, contains("replaceState(null, '', '/')"));
  });
}
