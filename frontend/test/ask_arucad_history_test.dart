import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/ask_arucad_store.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/features/guide/ask_arucad_screen.dart';

/// Chat history has to be on screen the moment the AICAD tab opens.
///
/// GET /ask/conversations returns summaries only — no messages — so showing
/// `conversations.first` straight from that list rendered an empty thread and
/// the history looked lost until the user re-tapped it in the drawer.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() => SharedPreferences.setMockInitialValues({}));

  Widget host(CampusRepository repository) => MaterialApp(
        home: AppLocale(
          language: AppLanguage.tr,
          child: AskArucadScreen(
            repository: repository,
            mapProvider: _NoopMap(),
            analyticsTracker: _NoopAnalytics(),
          ),
        ),
      );

  testWidgets('the newest conversation opens with its messages already there',
      (tester) async {
    final repo = _HistoryRepository(
      // What the list endpoint really returns: no messages.
      summaries: [
        AskArucadConversation(
          id: 'ask-1',
          title: 'Arkeoloji',
          messages: const [],
          updatedAt: DateTime(2026, 9, 18),
        ),
      ],
      // What fetching the thread by id returns.
      full: AskArucadConversation(
        id: 'ask-1',
        title: 'Arkeoloji',
        messages: [
          AskArucadMessage(
              fromUser: true,
              text: 'Arkeoloji bölümü',
              at: DateTime(2026, 9, 18)),
          AskArucadMessage(
              fromUser: false,
              text: 'Kazı ve müze çalışmaları',
              at: DateTime(2026, 9, 18)),
        ],
        updatedAt: DateTime(2026, 9, 18),
      ),
    );

    await tester.pumpWidget(host(repo));
    await tester.pumpAndSettle();

    expect(find.text('Arkeoloji bölümü'), findsOneWidget);
    expect(find.text('Kazı ve müze çalışmaları'), findsOneWidget);
    expect(repo.fetchedById, isTrue);
  });

  testWidgets('history still shows from local storage when the fetch fails',
      (tester) async {
    await AskArucadStore.save(AskArucadConversation(
      id: 'ask-1',
      title: 'Arkeoloji',
      messages: [
        AskArucadMessage(
            fromUser: true,
            text: 'Yerelden gelen soru',
            at: DateTime(2026, 9, 18)),
      ],
      updatedAt: DateTime(2026, 9, 18),
    ));

    final repo = _HistoryRepository(
      summaries: [
        AskArucadConversation(
          id: 'ask-1',
          title: 'Arkeoloji',
          messages: const [],
          updatedAt: DateTime(2026, 9, 18),
        ),
      ],
      full: null,
      throwOnFetch: true,
    );

    await tester.pumpWidget(host(repo));
    await tester.pumpAndSettle();

    expect(find.text('Yerelden gelen soru'), findsOneWidget);
  });

  testWidgets('a resolved campus place always exposes its 360 action',
      (tester) async {
    await tester.pumpWidget(host(_TourRepository()));
    await tester.pumpAndSettle();

    await tester.enterText(
      find.byType(TextField),
      '360 şekilde ana kampüs girişini açar mısın?',
    );
    await tester.testTextInput.receiveAction(TextInputAction.send);
    await tester.pumpAndSettle();

    expect(find.text('360° Aç'), findsOneWidget);
  });
}

class _TourRepository extends MockCampusRepository {
  static const entrance = CampusPlace(
    id: 'main-entrance',
    name: 'Ana kampüs girişi',
    category: 'Entrance',
    lat: 35.337,
    lng: 33.321,
    description: 'Ana giriş',
    distance: '',
    density: 'quiet',
    street: 'Şair Nedim Sokak No:11',
    tourUrl: 'https://360.arucad.edu.tr/Main/index.htm?media-name=ENTRY',
    tourTarget: 'ENTRY',
    accessible: true,
    photos: 0,
    rating: 0,
  );

  @override
  Future<List<CampusPlace>> getPlaces() async => const [entrance];

  @override
  Future<String> askGuide(
    String prompt, {
    List<({bool fromUser, String text})> history = const [],
    String? conversationId,
  }) async =>
      'Ana kampüs girişi için doğrulanmış 360° görünüm hazır.';
}

class _HistoryRepository extends MockCampusRepository {
  _HistoryRepository({
    required this.summaries,
    required this.full,
    this.throwOnFetch = false,
  });

  final List<AskArucadConversation> summaries;
  final AskArucadConversation? full;
  final bool throwOnFetch;
  bool fetchedById = false;

  @override
  Future<List<AskArucadConversation>> getAskConversations() async => summaries;

  @override
  Future<AskArucadConversation?> getAskConversation(String id) async {
    fetchedById = true;
    if (throwOnFetch) throw Exception('offline');

    return full;
  }
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
