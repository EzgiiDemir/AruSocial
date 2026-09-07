import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/network/media_url.dart';

void main() {
  test('rewrites /storage/media onto the CORS-safe API file route', () {
    MediaUrl.bindApiBase('http://127.0.0.1:4000/api/v1');
    expect(
      MediaUrl.resolve(
          'http://127.0.0.1:4000/storage/media/pcYPKXmALVT2mNra5WhhIlJe9lWxsDIF2t6DQmj4.jpg'),
      'http://127.0.0.1:4000/api/v1/media/file/pcYPKXmALVT2mNra5WhhIlJe9lWxsDIF2t6DQmj4.jpg',
    );
    expect(
      MediaUrl.resolve('/storage/media/abc.jpg'),
      'http://127.0.0.1:4000/api/v1/media/file/abc.jpg',
    );
  });

  test('leaves API media URLs unchanged', () {
    MediaUrl.bindApiBase('http://127.0.0.1:4000/api/v1');
    const already = 'http://127.0.0.1:4000/api/v1/media/id-1/file';
    expect(MediaUrl.resolve(already), already);
  });
}
