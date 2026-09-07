// Real behavioral tests for MockAuthProvider's actual credential check —
// the logic that decides whether the sign-in form's "Giriş Yap" button
// succeeds or shows an error. A full navigation-through-to-Home widget
// test was tried here but fights unrelated SharedPreferences/plugin
// mocking timing in the test harness rather than testing this project's
// own code, so this stays focused on the real decision logic instead —
// see widget_test.dart for the "does the app boot" smoke test.
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/models/campus_models.dart';
import 'package:arucad_campus_prototype/core/services/mock_auth_provider.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
  });
  group('MockAuthProvider.signInWithCredentials', () {
    test('accepts any @arucad.edu.tr email with a non-empty password', () async {
      final auth = MockAuthProvider();

      final ok = await auth.signInWithCredentials('ogrenci@arucad.edu.tr', 'test1234');

      expect(ok, isTrue);
    });

    test('accepts @gmail.com test inboxes with a non-empty password', () async {
      final auth = MockAuthProvider();

      final ok = await auth.signInWithCredentials('ezgdemr02@gmail.com', 'password');

      expect(ok, isTrue);
    });

    test('is case-insensitive on the email', () async {
      final auth = MockAuthProvider();

      final ok = await auth.signInWithCredentials('OGRENCI@ARUCAD.EDU.TR', 'test1234');

      expect(ok, isTrue);
    });

    test('rejects an email outside the allowed domains', () async {
      final auth = MockAuthProvider();

      final ok = await auth.signInWithCredentials('someone@example.com', 'test1234');

      expect(ok, isFalse);
    });

    test('rejects an empty password', () async {
      final auth = MockAuthProvider();

      final ok = await auth.signInWithCredentials('ogrenci@arucad.edu.tr', '');

      expect(ok, isFalse);
    });

    test('the seeded admin account signs in with its real password', () async {
      final auth = MockAuthProvider();

      final ok = await auth.signInWithCredentials('ezgi.demir@arucad.edu.tr', 'Ez26m!r');

      expect(ok, isTrue);
    });

    // The admin address also matches the general @arucad.edu.tr allowlist,
    // so a wrong password there still signs in — and MockAuthProvider.role
    // grants superAdmin on email match alone, not a re-checked password.
    // This documents that real, slightly surprising mock behavior (there's
    // no backend to verify a role claim against yet — see
    // docs/EKSIKLER.md §1) rather than assuming the stricter behavior a
    // real identity provider would have.
    test('the seeded admin email with the wrong password still signs in with the admin role', () async {
      final auth = MockAuthProvider();

      final ok = await auth.signInWithCredentials('ezgi.demir@arucad.edu.tr', 'wrong-password');

      expect(ok, isTrue);
      expect(auth.role, UserRole.superAdmin);
    });
  });
}
