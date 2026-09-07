import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/models/media_item.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/media_library_store.dart';
import 'package:arucad_campus_prototype/core/services/mock_campus_repository.dart';
import 'package:arucad_campus_prototype/core/services/rest_campus_repository.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  test('MediaItem.fromJson reads the GET /media payload', () {
    final item = MediaItem.fromJson({
      'id': 'media-1',
      'url': '/storage/media/garden.jpg',
      'fileName': 'garden.jpg',
      'uploadedAt': '2026-08-23T12:00:00.000Z',
      'uploadedBy': 'Editor',
      'usedIn': ['place-cover:atelier'],
    });

    expect(item.id, 'media-1');
    expect(item.url, '/api/v1/media/file/garden.jpg');
    expect(item.fileName, 'garden.jpg');
    expect(item.uploadedBy, 'Editor');
    expect(item.usedIn, ['place-cover:atelier']);
    expect(item.displaySrc, '/api/v1/media/file/garden.jpg');
    expect(item.dataUri, isEmpty);
  });

  test('MockCampusRepository media CRUD stays on MediaLibraryStore', () async {
    final repo = MockCampusRepository();
    final item = await repo.uploadMedia(
      Uint8List.fromList([1, 2, 3]),
      fileName: 'shot.jpg',
    );

    expect(item.dataUri, startsWith('data:image/jpeg;base64,'));
    expect(item.url, isNull);
    expect((await repo.getMedia()).map((m) => m.id), contains(item.id));

    await repo.renameMedia(item.id, 'renamed.jpg');
    expect((await repo.getMedia()).single.fileName, 'renamed.jpg');

    await repo.markMediaUsed(item.id, 'place-cover:atelier');
    expect((await repo.getMedia()).single.usedIn, contains('place-cover:atelier'));

    await repo.deleteMedia(item.id);
    expect((await repo.getMedia()).map((m) => m.id), isNot(contains(item.id)));
  });

  test('RestCampusRepository media calls hit /media and do not write MediaLibraryStore',
      () async {
    final calls = <http.Request>[];
    final mock = MockClient((request) async {
      calls.add(request);
      if (request.method == 'GET' && request.url.path.endsWith('/media')) {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'media-1',
                'url': '/storage/media/garden.jpg',
                'fileName': 'garden.jpg',
                'uploadedAt': '2026-08-23T12:00:00.000Z',
                'uploadedBy': 'Editor',
                'usedIn': <String>[],
              }
            ],
            'meta': {},
            'error': null,
          }),
          200,
        );
      }
      if (request.method == 'POST' &&
          request.url.path.endsWith('/media') &&
          (request.headers['content-type'] ?? '').contains('multipart/form-data')) {
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'media-2',
              'url': '/storage/media/new.jpg',
              'fileName': 'new.jpg',
              'uploadedAt': '2026-08-23T12:00:00.000Z',
              'uploadedBy': 'Editor',
              'usedIn': <String>[],
            },
            'meta': {},
            'error': null,
          }),
          201,
        );
      }
      if (request.method == 'POST' && request.url.path.endsWith('/media/media-1/delete')) {
        return http.Response(jsonEncode({'data': {'deleted': true}, 'meta': {}, 'error': null}), 200);
      }
      if (request.method == 'POST' && request.url.path.endsWith('/media/media-1')) {
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'media-1',
              'url': '/storage/media/garden.jpg',
              'fileName': 'renamed.jpg',
              'uploadedAt': '2026-08-23T12:00:00.000Z',
              'uploadedBy': 'Editor',
              'usedIn': ['event:1'],
            },
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

    final listed = await repo.getMedia();
    expect(listed.single.id, 'media-1');
    expect(listed.single.displaySrc, 'http://example.com/api/v1/media/file/garden.jpg');
    expect(listed.single.dataUri, isEmpty);

    final uploaded = await repo.uploadMedia(Uint8List.fromList([9, 9, 9]), fileName: 'new.jpg');
    expect(calls.last.method, 'POST');
    expect(calls.last.url.path, '/api/v1/media');
    expect(calls.last.headers['content-type'], contains('multipart/form-data'));
    expect(uploaded.url, 'http://example.com/api/v1/media/file/new.jpg');
    expect(uploaded.dataUri, isEmpty);
    expect(await MediaLibraryStore.items(), isEmpty);

    await repo.renameMedia('media-1', 'renamed.jpg');
    expect(calls.last.url.path, '/api/v1/media/media-1');
    expect(jsonDecode(calls.last.body)['fileName'], 'renamed.jpg');

    await repo.deleteMedia('media-1');
    expect(calls.last.url.path, '/api/v1/media/media-1/delete');
  });

  test('REST media UI does not import MediaLibraryStore as a source', () {
    const paths = [
      'lib/features/admin/media/media_library_screen.dart',
      'lib/features/admin/content_blocks/content_block_editor.dart',
      'lib/features/place/place_detail_screen.dart',
      'lib/features/admin/admin_panel_screen.dart',
      'lib/features/widgets/block_renderer.dart',
      'lib/core/services/rest_campus_repository.dart',
    ];
    for (final rel in paths) {
      final src = File(rel).readAsStringSync();
      expect(src.contains('media_library_store.dart'), isFalse, reason: rel);
      expect(src.contains('MediaLibraryStore.'), isFalse, reason: rel);
    }
  });
}
