import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/features/place/place_detail_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_widgets.dart';

/// The hero used to be a grey placeholder for every place that had no
/// uploaded cover, which was all of them. It now falls back to the bundled
/// campus photograph, and only then to the line-art mark.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() => SharedPreferences.setMockInitialValues({}));

  Future<void> pumpDetail(WidgetTester tester, CampusPlace place) async {
    await tester.pumpWidget(MaterialApp(
      home: AppLocale(
        language: AppLanguage.tr,
        child: PlaceDetailScreen(
          place: place,
          repository: MockCampusRepository(),
          mapProvider: _NoopMap(),
          analyticsTracker: _NoopAnalytics(),
        ),
      ),
    ));
    await tester.pump();
  }

  Iterable<String> assetImagePaths(WidgetTester tester) => tester
      .widgetList<Image>(find.byType(Image))
      .map((w) => w.image)
      .whereType<AssetImage>()
      .map((i) => i.assetName);

  testWidgets('a catalogue place shows its bundled photo', (tester) async {
    await pumpDetail(
        tester, _place(id: 'age-of-bronze', name: 'Age of Bronze'));

    expect(
      assetImagePaths(tester),
      contains('assets/images/places/photos/18-age-of-bronze.jpg'),
    );
  });

  testWidgets('the photo keeps the 360° tour hint on it', (tester) async {
    await pumpDetail(
        tester, _place(id: 'age-of-bronze', name: 'Age of Bronze'));

    expect(find.text('360° turu görüntülemek için dokun'), findsOneWidget);
  });

  testWidgets('a place outside the catalogue keeps the line-art placeholder',
      (tester) async {
    await pumpDetail(
        tester, _place(id: 'unknown', name: 'Somewhere Nobody Drew'));

    expect(
      assetImagePaths(tester).where((a) => a.contains('/photos/')),
      isEmpty,
    );
    expect(find.byType(PlaceLineArtIcon), findsOneWidget);
  });
}

CampusPlace _place({required String id, required String name}) => CampusPlace(
      id: id,
      name: name,
      category: 'Workshop',
      lat: 35.333593,
      lng: 33.330680,
      description: '',
      distance: '',
      density: 'quiet',
      street: '',
      tourUrl: null,
      accessible: true,
      photos: 0,
      rating: 0,
    );

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
