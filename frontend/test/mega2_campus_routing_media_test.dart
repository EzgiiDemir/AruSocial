import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/models/campus_directory.dart';
import 'package:arucad_campus_prototype/core/models/media_item.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/directions_result.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/core/services/rest_campus_repository.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  test('CampusBuilding/Floor/Room parse directory hierarchy JSON', () {
    expect(
      CampusBuilding.fromJson({'id': 'A Blok', 'name': 'A Blok', 'entryCount': 3}).entryCount,
      3,
    );
    expect(
      CampusFloor.fromJson(
              {'id': '1', 'name': '1', 'building': 'A Blok', 'entryCount': 2})
          .building,
      'A Blok',
    );
    final room = CampusRoom.fromJson({
      'id': 'd1',
      'room': '104',
      'building': 'A Blok',
      'floor': '1',
      'occupantName': 'Öğrenci İşleri',
    });
    expect(room.title, '104');
  });

  test('WalkingRoute.fromJson maps provider points and steps', () {
    final route = WalkingRoute.fromJson({
      'points': [
        {'lat': 35.33, 'lng': 33.32},
        {'lat': 35.34, 'lng': 33.31},
      ],
      'distanceMeters': 120.5,
      'durationSeconds': 90,
      'steps': [
        {'instruction': 'left turn', 'distanceMeters': 60},
      ],
      'provider': 'osrm',
    });
    expect(route.points.length, 2);
    expect(route.toRouteResult().fromProvider, isTrue);
    expect(route.toRouteResult().steps.first, 'left turn');
  });

  test('MediaItem parses mimeType and moderationStatus; isVideo from mime', () {
    final video = MediaItem.fromJson({
      'id': 'm1',
      'url': '/storage/media/video/clip.mp4',
      'fileName': 'clip.mp4',
      'uploadedAt': '2026-08-25T12:00:00.000Z',
      'uploadedBy': 'Editor',
      'mimeType': 'video/mp4',
      'moderationStatus': 'pending',
      'usedIn': <String>[],
    });
    expect(video.isVideo, isTrue);
    expect(video.moderationStatus, 'pending');
  });

  test('MockCampusRepository directory drill-down and video queue', () async {
    final repo = MockCampusRepository();
    final buildings = await repo.getDirectoryBuildings();
    expect(buildings.map((b) => b.name), containsAll(['A Blok', 'Atelier']));

    final floors = await repo.getDirectoryFloors('A Blok');
    expect(floors.map((f) => f.name), containsAll(['1', '2']));

    final rooms = await repo.getDirectoryRooms('A Blok', '1');
    expect(rooms, isNotEmpty);

    expect(await repo.getWalkingRoute(
      fromLat: 35.33,
      fromLng: 33.32,
      toLat: 35.34,
      toLng: 33.31,
    ), isNull);

    final video = await repo.uploadMedia(Uint8List.fromList([1, 2, 3]), fileName: 'clip.mp4');
    expect(video.moderationStatus, 'pending');
    final queue = await repo.getModerationQueue();
    expect(queue.items.map((m) => m.id), contains(video.id));

    await repo.resolveModerationQueueItem(video.id, action: 'approved');
    expect((await repo.getModerationQueue()).items, isEmpty);

    final draft = await repo.draftEventFromPoster(
      Uint8List.fromList([9, 9]),
      fileName: 'spring_fest.jpg',
    );
    expect(draft.draft, isTrue);
    expect(draft.aiDraft, isTrue);
    expect(draft.workflowStatus, 'draft');
  });

  test('RestCampusRepository hits Mega-2 directory/routing/queue/poster paths', () async {
    final calls = <http.Request>[];
    final mock = MockClient((request) async {
      calls.add(request);
      final path = request.url.path;
      if (path.endsWith('/directory/buildings')) {
        return http.Response(
          jsonEncode({
            'data': [
              {'id': 'A Blok', 'name': 'A Blok', 'entryCount': 2}
            ],
            'meta': {},
            'error': null,
          }),
          200,
        );
      }
      if (path.contains('/floors/') && path.endsWith('/rooms')) {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'd1',
                'room': '104',
                'building': 'A Blok',
                'floor': '1',
                'occupantName': 'Ofis',
              }
            ],
            'meta': {},
            'error': null,
          }),
          200,
        );
      }
      if (path.contains('/floors')) {
        return http.Response(
          jsonEncode({
            'data': [
              {'id': '1', 'name': '1', 'building': 'A Blok', 'entryCount': 1}
            ],
            'meta': {},
            'error': null,
          }),
          200,
        );
      }
      if (path.endsWith('/routing/directions')) {
        return http.Response(
          jsonEncode({
            'error': {
              'code': 'ROUTING_NOT_CONFIGURED',
              'message': 'not set',
            },
            'data': null,
            'meta': {},
          }),
          501,
        );
      }
      if (path.endsWith('/admin/moderation/queue')) {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'media-v',
                'url': '/storage/media/video/a.mp4',
                'fileName': 'a.mp4',
                'mimeType': 'video/mp4',
                'uploadedAt': '2026-08-25T12:00:00.000Z',
                'uploadedBy': 'Editor',
                'moderationStatus': 'pending',
              }
            ],
            'meta': {'page': 1, 'perPage': 20, 'total': 1, 'hasMore': false},
            'error': null,
          }),
          200,
        );
      }
      if (path.contains('/admin/moderation/queue/') && path.endsWith('/resolve')) {
        return http.Response(
          jsonEncode({
            'data': {'id': 'media-v', 'moderationStatus': 'approved'},
            'meta': {},
            'error': null,
          }),
          200,
        );
      }
      if (path.endsWith('/admin/events/draft-from-poster')) {
        return http.Response(
          jsonEncode({
            'data': {
              'event': {
                'id': 'event-1',
                'title': 'Poster Event',
                'time': '14:00',
                'placeName': 'Atelier',
                'category': 'Etkinlik',
                'attendees': 0,
                'xp': 30,
                'draft': true,
                'workflowStatus': 'draft',
                'aiDraft': true,
                'description': '',
                'organizer': '',
              },
              'incomplete': false,
              'mediaId': 'media-p',
            },
            'meta': {},
            'error': null,
          }),
          201,
        );
      }
      return http.Response(jsonEncode({'data': {}, 'meta': {}, 'error': null}), 200);
    });

    final repo = RestCampusRepository(
      client: ApiClient(baseUrl: 'http://example.com/api/v1', client: mock),
    );

    expect((await repo.getDirectoryBuildings()).single.name, 'A Blok');
    expect((await repo.getDirectoryFloors('A Blok')).single.name, '1');
    expect((await repo.getDirectoryRooms('A Blok', '1')).single.room, '104');
    expect(
        await repo.getWalkingRoute(
            fromLat: 35.33, fromLng: 33.32, toLat: 35.34, toLng: 33.31),
        isNull);

    final queue = await repo.getModerationQueue();
    expect(queue.items.single.isVideo, isTrue);
    await repo.resolveModerationQueueItem('media-v', action: 'approved');

    final draft = await repo.draftEventFromPoster(
      Uint8List.fromList([1]),
      fileName: 'poster.jpg',
    );
    expect(draft.aiDraft, isTrue);
    expect(draft.draft, isTrue);

    expect(calls.any((c) => c.url.path.endsWith('/directory/buildings')), isTrue);
    expect(calls.any((c) => c.url.path.endsWith('/routing/directions')), isTrue);
    expect(calls.any((c) => c.url.path.endsWith('/admin/moderation/queue')), isTrue);
    expect(calls.any((c) => c.url.path.endsWith('/admin/events/draft-from-poster')), isTrue);
  });
}
