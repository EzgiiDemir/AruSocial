import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/navigation/app_deep_link.dart';

void main() {
  test('parses aruverse custom scheme links', () {
    final link = AppDeepLink.tryParse('aruverse://place/garden');
    expect(link?.kind, 'place');
    expect(link?.id, 'garden');
  });

  test('parses flutter defaultRouteName paths', () {
    final link = AppDeepLink.tryParse('/chat/ada@arucad.edu.tr');
    expect(link?.kind, 'chat');
    expect(link?.id, 'ada@arucad.edu.tr');
  });

  test('ignores unknown routes', () {
    expect(AppDeepLink.tryParse('/admin'), isNull);
    expect(AppDeepLink.tryParse('/'), isNull);
  });
}
