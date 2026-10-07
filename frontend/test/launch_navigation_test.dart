import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/core/services/tour_launcher.dart';
import 'package:arucad_campus_prototype/features/services/building_directory_screen.dart';
import 'package:arucad_campus_prototype/features/widgets/feed_post_media.dart';

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  test('scene links preserve the campus query parameters', () {
    final uri = Uri.parse(composeTourUrl(
        'https://360.arucad.edu.tr/index.htm?campus=main', '?scene=12'));
    expect(uri.queryParameters, {'campus': 'main', 'scene': '12'});
  });

  testWidgets('directory exposes search and remains usable on a narrow screen',
      (tester) async {
    await tester.binding.setSurfaceSize(const Size(320, 740));
    addTearDown(() => tester.binding.setSurfaceSize(null));
    await tester.pumpWidget(MaterialApp(
        home: BuildingDirectoryScreen(repository: MockCampusRepository())));
    await tester.pumpAndSettle();
    expect(find.text('Bina, oda, birim veya personel ara'), findsOneWidget);
    await tester.enterText(find.byType(TextField), 'nonexistent building');
    await tester.pumpAndSettle();
    expect(find.text('Bina bulunamadı.'), findsOneWidget);
    expect(tester.takeException(), isNull);
  });

  // Video was removed from the product on 14 September 2026. This replaces
  // a test asserting a clip rendered a labelled tile with a playback
  // action — there is no player to reach any more.
  testWidgets('an image post renders its picture and nothing else',
      (tester) async {
    await tester.pumpWidget(const MaterialApp(
        home: Scaffold(
            body:
                FeedPostMedia(imageUrl: 'https://example.test/media/x.jpg'))));

    expect(find.text('Oynat'), findsNothing);
    expect(find.text('Video'), findsNothing);
    expect(tester.takeException(), isNull);
  });
}
