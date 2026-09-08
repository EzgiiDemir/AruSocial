import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/features/admin/admin_panel_screen.dart';
import 'package:arucad_campus_prototype/features/auth/access_denied_screen.dart';
import 'package:arucad_campus_prototype/features/home/campus_live_map.dart';
import 'package:arucad_campus_prototype/features/profile/profile_screen.dart';
import 'package:arucad_campus_prototype/features/trainer/trainer_panel_screen.dart';

class _NoopMap implements MapProvider {
  @override
  Future<void> startRoute({required String destination, bool accessibleOnly = false}) async {}

  @override
  Future<void> openTour(String tourUrl) async {}
}

class _NoopAnalytics implements AnalyticsTracker {
  @override
  void track(String event, [Map<String, Object?> properties = const {}]) {}
}

const _student = CampusUser(
  id: 'u1',
  name: 'Öğrenci',
  role: 'student',
  level: 1,
  xp: 0,
  places: 0,
  events: 0,
  memories: 0,
  interests: [],
);

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  testWidgets('student cannot open admin panel via route', (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: AdminPanelScreen(
        repository: MockCampusRepository(),
        role: UserRole.student,
        user: _student,
        onLogout: () {},
      ),
    ));
    await tester.pump();
    expect(find.byType(AccessDeniedScreen), findsOneWidget);
    expect(find.text('Yönetim paneli'), findsOneWidget);
  });

  testWidgets('student cannot open trainer panel via route', (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: TrainerPanelScreen(
        repository: MockCampusRepository(),
        user: _student,
        role: UserRole.student,
        onLogout: () {},
      ),
    ));
    await tester.pump();
    expect(find.byType(AccessDeniedScreen), findsOneWidget);
    expect(find.text('Eğitmen paneli'), findsOneWidget);
  });

  testWidgets('superAdmin settings show both management tiles', (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: ProfileScreen(
                onLogout: () {},
user: _student,
        repository: MockCampusRepository(),
        mapProvider: _NoopMap(),
        analyticsTracker: _NoopAnalytics(),
        role: UserRole.superAdmin,
        language: 'TR',
        locationVisibility: CampusVisibility.ghost,
        nearbyDiscoverable: false,
        personalization: true,
        isPrivateProfile: false,
        initialSection: SettingsSection.system,
        onLanguage: (_) {},
        onLocationVisibility: (_) {},
        onNearbyDiscoverable: (_) {},
        onPersonalization: (_) {},
        onPrivateProfile: (_) {},
      ),
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    expect(find.text('Yönetim paneli'), findsOneWidget);
    expect(find.text('Eğitmen paneli'), findsOneWidget);
  });

  testWidgets('student settings hide admin and trainer tiles', (tester) async {
    await tester.pumpWidget(MaterialApp(
      home: ProfileScreen(
                onLogout: () {},
user: _student,
        repository: MockCampusRepository(),
        mapProvider: _NoopMap(),
        analyticsTracker: _NoopAnalytics(),
        role: UserRole.student,
        language: 'TR',
        locationVisibility: CampusVisibility.ghost,
        nearbyDiscoverable: false,
        personalization: true,
        isPrivateProfile: false,
        initialSection: SettingsSection.system,
        onLanguage: (_) {},
        onLocationVisibility: (_) {},
        onNearbyDiscoverable: (_) {},
        onPersonalization: (_) {},
        onPrivateProfile: (_) {},
      ),
    ));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 50));
    expect(find.text('Yönetim paneli'), findsNothing);
    expect(find.text('Eğitmen paneli'), findsNothing);
  });
}
