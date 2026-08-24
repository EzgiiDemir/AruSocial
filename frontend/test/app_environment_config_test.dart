import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/app/config/app_config.dart';

void main() {
  test('debug with no dart-define stays mock and uses loopback API', () {
    final config = AppConfig.fromEnvironment(
      isRelease: false,
      isWeb: true,
      useRestApi: '',
      appEnv: 'local',
      apiBaseUrl: '',
    );
    expect(config.useRestApi, isFalse);
    expect(config.environment, AppEnvironment.local);
    expect(config.demoMode, isTrue);
    expect(config.apiBaseUrl, 'http://localhost:4000/api/v1');
  });

  test('explicit REST local keeps android emulator loopback', () {
    final config = AppConfig.fromEnvironment(
      isRelease: false,
      isWeb: false,
      platform: TargetPlatform.android,
      useRestApi: 'true',
      appEnv: 'local',
      apiBaseUrl: '',
    );
    expect(config.useRestApi, isTrue);
    expect(config.apiBaseUrl, 'http://10.0.2.2:4000/api/v1');
  });

  test('release without USE_REST_API throws rather than shipping mock', () {
    expect(
      () => AppConfig.fromEnvironment(isRelease: true, useRestApi: '', appEnv: 'local'),
      throwsA(isA<StateError>()),
    );
  });

  test('release production mock is rejected', () {
    expect(
      () => AppConfig.fromEnvironment(
        isRelease: true,
        useRestApi: 'false',
        appEnv: 'production',
      ),
      throwsA(isA<StateError>()),
    );
  });

  test('release staging REST without API_BASE_URL does not fall back to localhost', () {
    expect(
      () => AppConfig.fromEnvironment(
        isRelease: true,
        useRestApi: 'true',
        appEnv: 'staging',
        apiBaseUrl: '',
      ),
      throwsA(isA<StateError>()),
    );
  });

  test('release production REST uses the provided public API URL', () {
    final config = AppConfig.fromEnvironment(
      isRelease: true,
      useRestApi: 'true',
      appEnv: 'production',
      apiBaseUrl: 'https://api.example.com/api/v1',
      reverbAppKey: 'prod-key',
      reverbHost: 'ws.example.com',
      reverbPort: 443,
      reverbScheme: 'https',
      sentryDsn: '',
      firebaseProjectId: 'campus-prod',
    );
    expect(config.useRestApi, isTrue);
    expect(config.environment, AppEnvironment.production);
    expect(config.apiBaseUrl, 'https://api.example.com/api/v1');
    expect(config.reverbAppKey, 'prod-key');
    expect(config.reverbHost, 'ws.example.com');
    expect(config.firebaseProjectId, 'campus-prod');
    expect(config.sentryDsn, isEmpty);
  });

  test('demo factory remains mock for widget tests', () {
    final demo = AppConfig.demo();
    expect(demo.useRestApi, isFalse);
    expect(demo.environment, AppEnvironment.local);
  });
}
