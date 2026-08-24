import 'dart:convert';
import 'dart:io';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/config/campus_life_config.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/core/services/rest_campus_repository.dart';
import 'package:arucad_campus_prototype/features/guide/guide_context.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  test('CampusFoodVenue.fromJson reads the GET /food-venues payload', () {
    final venue = CampusFoodVenue.fromJson({
      'id': 'food-the-garden',
      'name': 'The Garden',
      'hours': '08:00–20:00',
      'menuFileUrl': null,
      'dailyMenus': [
        {
          'date': '2026-08-23',
          'items': ['Mercimek Çorbası', 'Pilav'],
          'price': '85₺',
          'hours': '11:00–14:00',
        }
      ],
    });

    expect(venue.id, 'food-the-garden');
    expect(venue.name, 'The Garden');
    expect(venue.hours, '08:00–20:00');
    expect(venue.menuForDay(DateTime(2026, 8, 23))?.items, ['Mercimek Çorbası', 'Pilav']);
    expect(venue.menuForDay(DateTime(2026, 8, 24)), isNull);
  });

  test('MockCampusRepository food venues come from the mock seed, not REST', () async {
    final repo = MockCampusRepository();
    final venues = await repo.getFoodVenues();

    expect(venues, isNotEmpty);
    expect(venues.map((v) => v.id), contains('garden'));

    await repo.upsertFoodVenue(const CampusFoodVenue(id: 'food-test', name: 'Test Cafe'));
    final afterCreate = await repo.getFoodVenues();
    expect(afterCreate.map((v) => v.id), contains('food-test'));

    await repo.deleteFoodVenue('food-test');
    final afterDelete = await repo.getFoodVenues();
    expect(afterDelete.map((v) => v.id), isNot(contains('food-test')));
  });

  test('MockCampusRepository menu upsert and delete stay on the mock venue', () async {
    final repo = MockCampusRepository();
    final day = DateTime(2026, 8, 23);

    await repo.upsertFoodMenu(
      'garden',
      DailyMenu(date: day, items: const ['Çorba'], price: '70₺'),
    );
    var garden = (await repo.getFoodVenues()).firstWhere((v) => v.id == 'garden');
    expect(garden.menuForDay(day)?.items, ['Çorba']);

    await repo.deleteFoodMenu('garden', day);
    garden = (await repo.getFoodVenues()).firstWhere((v) => v.id == 'garden');
    expect(garden.menuForDay(day), isNull);
  });

  test('GuideContext.load reads food venues from the repository', () async {
    final ctx = await GuideContext.load(MockCampusRepository());
    expect(ctx.foodVenues.map((v) => v.id), contains('garden'));
  });

  test('Explore, Guide and Admin food UI do not import AdminContentStore', () {
    const paths = [
      'lib/features/explore/explore_screen.dart',
      'lib/features/guide/guide_context.dart',
      'lib/features/admin/admin_panel_screen.dart',
    ];
    for (final rel in paths) {
      final src = File(rel).readAsStringSync();
      expect(src.contains('admin_content_store.dart'), isFalse, reason: rel);
      expect(src.contains('AdminContentStore.'), isFalse, reason: rel);
    }
  });

  test('RestCampusRepository food CRUD hits the existing /food-venues contract', () async {
    final calls = <http.Request>[];
    final mock = MockClient((request) async {
      calls.add(request);
      if (request.method == 'GET' && request.url.path.endsWith('/food-venues')) {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'food-the-garden',
                'name': 'The Garden',
                'hours': '08:00-20:00',
                'menuFileUrl': null,
                'dailyMenus': [
                  {
                    'date': '2026-08-23',
                    'items': ['Pilav'],
                    'price': '85 TL',
                    'hours': null,
                  }
                ],
              }
            ],
            'meta': {},
            'error': null,
          }),
          200,
        );
      }
      return http.Response(jsonEncode({'data': {'ok': true}, 'meta': {}, 'error': null}), 200);
    });

    final repo = RestCampusRepository(
      client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
    );

    final venues = await repo.getFoodVenues();
    expect(venues.single.id, 'food-the-garden');
    expect(venues.single.menuForDay(DateTime(2026, 8, 23))?.items, ['Pilav']);

    await repo.upsertFoodVenue(const CampusFoodVenue(id: 'food-x', name: 'Cafe'));
    expect(calls.last.method, 'POST');
    expect(calls.last.url.path, '/api/v1/admin/food-venues');
    expect(jsonDecode(calls.last.body)['id'], 'food-x');

    await repo.upsertFoodMenu(
      'food-x',
      DailyMenu(date: DateTime(2026, 8, 23), items: const ['Soup']),
    );
    expect(calls.last.url.path, '/api/v1/admin/food-venues/food-x/menus');
    expect(jsonDecode(calls.last.body)['date'], '2026-08-23');

    await repo.deleteFoodMenu('food-x', DateTime(2026, 8, 23));
    expect(calls.last.url.path, '/api/v1/admin/food-venues/food-x/menus/2026-08-23/delete');

    await repo.deleteFoodVenue('food-x');
    expect(calls.last.url.path, '/api/v1/admin/food-venues/food-x/delete');
  });
}
