import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:arucad_campus_prototype/core/auth/app_settings_store.dart';
import 'package:arucad_campus_prototype/core/auth/rest_auth_provider.dart';
import 'package:arucad_campus_prototype/core/auth/session_store.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';
import 'package:arucad_campus_prototype/core/services/mock_auth_provider.dart';

Map<String, dynamic> _envelope(dynamic data) => {
      'data': data,
      'meta': {'request_id': 'req-test'},
      'error': null,
    };

RestAuthProvider _rest(http.Client client) => RestAuthProvider(
      client: ApiClient(baseUrl: 'http://example.com/api/v1', client: client),
    );

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  setUp(() {
    SharedPreferences.setMockInitialValues({});
    ApiClient.onSessionInvalid = null;
  });

  tearDown(() {
    ApiClient.onSessionInvalid = null;
  });

  group('MockAuthProvider biometric', () {
    test('rejects biometric when no stored session exists', () async {
      final auth = MockAuthProvider();
      final ok = await auth.unlockWithBiometrics(email: 'a@arucad.edu.tr');
      expect(ok, isFalse);
      expect(auth.currentEmail, isNull);
    });

    test('unlocks only the stored session after a real password login', () async {
      final auth = MockAuthProvider();
      expect(await auth.signInWithCredentials('a@arucad.edu.tr', 'pass'), isTrue);

      final unlocked = await auth.unlockWithBiometrics(email: 'a@arucad.edu.tr');
      expect(unlocked, isTrue);
      expect(auth.currentEmail, 'a@arucad.edu.tr');
    });

    test('does not sign in when enrolled email does not match stored session', () async {
      final auth = MockAuthProvider();
      await auth.signInWithCredentials('a@arucad.edu.tr', 'pass');

      final ok = await auth.unlockWithBiometrics(email: 'b@arucad.edu.tr');
      expect(ok, isFalse);
    });

    test('logout invalidates biometric session', () async {
      final auth = MockAuthProvider();
      await auth.signInWithCredentials('a@arucad.edu.tr', 'pass');
      await auth.signOut();

      expect(await SessionStore.token(), isNull);
      expect(await auth.unlockWithBiometrics(email: 'a@arucad.edu.tr'), isFalse);
    });

    test('user B cannot restore user A after A logged out', () async {
      final auth = MockAuthProvider();
      await auth.signInWithCredentials('a@arucad.edu.tr', 'pass');
      await auth.signOut();
      await auth.signInWithCredentials('b@arucad.edu.tr', 'pass');

      expect(await auth.unlockWithBiometrics(email: 'a@arucad.edu.tr'), isFalse);
      expect(await auth.unlockWithBiometrics(email: 'b@arucad.edu.tr'), isTrue);
      expect(auth.currentEmail, 'b@arucad.edu.tr');
    });
  });

  group('RestAuthProvider biometric', () {
    test('rejects biometric when SessionStore is empty', () async {
      final auth = _rest(MockClient((_) async => http.Response('{}', 500)));
      expect(await auth.unlockWithBiometrics(email: 'a@arucad.edu.tr'), isFalse);
    });

    test('successful biometric validates GET /me then opens the stored user', () async {
      final auth = _rest(MockClient((request) async {
        expect(request.url.path, '/api/v1/me');
        return http.Response(jsonEncode(_envelope({'id': '1', 'role': 'student'})), 200);
      }));
      await SessionStore.save(
        token: 'sanctum.a',
        email: 'a@arucad.edu.tr',
      );

      expect(await auth.unlockWithBiometrics(email: 'a@arucad.edu.tr'), isTrue);
      expect(auth.currentEmail, 'a@arucad.edu.tr');
    });

    test('expired token is cleared and biometric does not authenticate', () async {
      final auth = _rest(MockClient((_) async {
        return http.Response(
          jsonEncode({
            'error': {'code': 'AUTH_REQUIRED', 'message': 'expired'},
          }),
          401,
        );
      }));
      await SessionStore.save(token: 'expired', email: 'a@arucad.edu.tr');

      expect(await auth.unlockWithBiometrics(email: 'a@arucad.edu.tr'), isFalse);
      expect(await SessionStore.token(), isNull);
      expect(auth.currentEmail, isNull);
    });

    test('enrolled email A cannot unlock stored session B', () async {
      final auth = _rest(MockClient((_) async {
        return http.Response(jsonEncode(_envelope({'id': '1'})), 200);
      }));
      await SessionStore.save(token: 'token-b', email: 'b@arucad.edu.tr');

      expect(await auth.unlockWithBiometrics(email: 'a@arucad.edu.tr'), isFalse);
      expect(await SessionStore.token(), 'token-b');
    });
  });

  test('disableBiometric after logout leaves no enrolled email', () async {
    await AppSettingsStore.enableBiometric(
      method: BiometricMethod.fingerprint,
      email: 'a@arucad.edu.tr',
    );
    await AppSettingsStore.disableBiometric();
    expect(await AppSettingsStore.biometricEnabled(), isFalse);
    expect(await AppSettingsStore.biometricEmail(), isNull);
  });
}
