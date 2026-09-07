import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/auth/session_store.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });

  test('student, admin and trainer portals keep fully independent sessions', () async {
    await SessionStore.save(token: 'student-token', email: 'student@arucad.edu.tr', portal: 'student');
    await SessionStore.save(token: 'admin-token', email: 'admin@arucad.edu.tr', portal: 'admin');
    await SessionStore.save(token: 'trainer-token', email: 'trainer@arucad.edu.tr', portal: 'trainer');

    expect(await SessionStore.token(portal: 'student'), 'student-token');
    expect(await SessionStore.token(portal: 'admin'), 'admin-token');
    expect(await SessionStore.token(portal: 'trainer'), 'trainer-token');
  });

  test('signing out of one portal never clears another', () async {
    await SessionStore.save(token: 'student-token', email: 'student@arucad.edu.tr', portal: 'student');
    await SessionStore.save(token: 'admin-token', email: 'admin@arucad.edu.tr', portal: 'admin');

    await SessionStore.clear(portal: 'admin');

    expect(await SessionStore.token(portal: 'admin'), isNull);
    expect(await SessionStore.token(portal: 'student'), 'student-token');
  });

  test('default portal is student, for call sites that predate scoping', () async {
    await SessionStore.save(token: 'unscoped-token', email: 'x@arucad.edu.tr');
    expect(await SessionStore.token(), 'unscoped-token');
    expect(await SessionStore.token(portal: 'student'), 'unscoped-token');
  });
}
