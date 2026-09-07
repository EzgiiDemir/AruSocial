import 'package:flutter/foundation.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/app/config/app_config.dart';

void main() {
  test('debug with no dart-define defaults to REST and uses loopback API', () {
    final config = AppConfig.fromEnvironment(
      isRelease: false,
      isWeb: true,
      useRestApi: '',
      appEnv: 'local',
      apiBaseUrl: '',
    );
    expect(config.useRestApi, isTrue);
    expect(config.environment, AppEnvironment.local);
    expect(config.demoMode, isFalse);
    expect(config.apiBaseUrl, 'http://127.0.0.1:4000/api/v1');
  });

  test('explicit USE_REST_API=false keeps offline mock', () {
    final config = AppConfig.fromEnvironment(
      isRelease: false,
      isWeb: true,
      useRestApi: 'false',
      appEnv: 'local',
      apiBaseUrl: '',
    );
    expect(config.useRestApi, isFalse);
    expect(config.demoMode, isTrue);
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

  test('release without API_BASE_URL fails before the login UI is mounted', () {
    expect(
      () => AppConfig.fromEnvironment(
        isRelease: true,
        isWeb: false,
        platform: TargetPlatform.android,
        useRestApi: '',
        appEnv: 'local',
        apiBaseUrl: '',
      ),
      throwsA(isA<StateError>()),
    );
  });

  test('release without USE_REST_API uses REST when API_BASE_URL is public',
      () {
    final config = AppConfig.fromEnvironment(
      isRelease: true,
      useRestApi: '',
      appEnv: 'production',
      apiBaseUrl: 'https://api.example.com/api/v1',
    );
    expect(config.useRestApi, isTrue);
    expect(config.demoMode, isFalse);
    expect(config.apiBaseUrl, 'https://api.example.com/api/v1');
  });

  test('release + localhost API URL is rejected', () {
    expect(
      () => AppConfig.fromEnvironment(
        isRelease: true,
        useRestApi: 'true',
        appEnv: 'local',
        apiBaseUrl: 'http://127.0.0.1:4000/api/v1',
      ),
      throwsA(isA<StateError>()),
    );
    expect(
      () => AppConfig.fromEnvironment(
        isRelease: true,
        useRestApi: 'true',
        appEnv: 'production',
        apiBaseUrl: 'http://localhost:4000/api/v1',
      ),
      throwsA(isA<StateError>()),
    );
    expect(
      () => AppConfig.fromEnvironment(
        isRelease: true,
        useRestApi: 'true',
        appEnv: 'local',
        apiBaseUrl: 'http://10.0.2.2:4000/api/v1',
      ),
      throwsA(isA<StateError>()),
    );
  });

  test('release LAN URL is accepted so a debug APK on hotspot still works', () {
    final config = AppConfig.fromEnvironment(
      isRelease: true,
      isWeb: false,
      platform: TargetPlatform.android,
      useRestApi: 'true',
      appEnv: 'local',
      apiBaseUrl: 'http://192.168.1.8:4000/api/v1',
    );
    expect(config.useRestApi, isTrue);
    expect(config.apiBaseUrl, 'http://192.168.1.8:4000/api/v1');
  });

  test('release staging/production REST without URL is rejected', () {
    expect(
      () => AppConfig.fromEnvironment(
        isRelease: true,
        useRestApi: '',
        appEnv: 'staging',
        apiBaseUrl: '',
      ),
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

  test(
      'release staging REST without API_BASE_URL does not fall back to localhost',
      () {
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

  test('copyWith REST flips demoMode off', () {
    final config = AppConfig.demo().copyWith(
      useRestApi: true,
      apiBaseUrl: 'http://192.168.1.8:4000/api/v1',
    );
    expect(config.useRestApi, isTrue);
    expect(config.demoMode, isFalse);
    expect(config.apiBaseUrl, 'http://192.168.1.8:4000/api/v1');
  });

  test('buildLocalApiBaseUrl turns host + 4000 into the Laravel API root', () {
    expect(
      AppConfig.buildLocalApiBaseUrl('192.168.1.8', port: 4000),
      'http://192.168.1.8:4000/api/v1',
    );
    expect(
      AppConfig.buildLocalApiBaseUrl('192.168.1.8:4000'),
      'http://192.168.1.8:4000/api/v1',
    );
    expect(
      AppConfig.buildLocalApiBaseUrl('http://192.168.1.8:4000/api/v1'),
      'http://192.168.1.8:4000/api/v1',
    );
    expect(AppConfig.buildLocalApiBaseUrl(''), isEmpty);
    expect(
      AppConfig.buildLocalApiBaseUrl('localhost'),
      'http://127.0.0.1:4000/api/v1',
    );
  });

  test('isLoopbackHost catches localhost aliases', () {
    expect(AppConfig.isLoopbackHost('127.0.0.1'), isTrue);
    expect(AppConfig.isLoopbackHost('localhost'), isTrue);
    expect(AppConfig.isLoopbackHost('http://10.0.2.2:4000/api/v1'), isTrue);
    expect(AppConfig.isLoopbackHost('192.168.1.8'), isFalse);
    expect(AppConfig.isLoopbackHost('https://api.example.com/api/v1'), isFalse);
  });

  test('release runtime override cannot alter build-time API configuration',
      () {
    final compiled = AppConfig.fromEnvironment(
      isRelease: true,
      useRestApi: 'true',
      appEnv: 'production',
      apiBaseUrl: 'https://api.example.com/api/v1',
    );
    expect(
        compiled
            .applyRuntimeOverride(isRelease: true, useRestApi: false)
            .useRestApi,
        isTrue);
    expect(
      compiled
          .applyRuntimeOverride(
              isRelease: true, useRestApi: true, host: '127.0.0.1')
          .apiBaseUrl,
      'https://api.example.com/api/v1',
    );
    expect(
      compiled
          .applyRuntimeOverride(
              isRelease: true,
              useRestApi: true,
              host: '10.20.30.40',
              port: 4000)
          .apiBaseUrl,
      'https://api.example.com/api/v1',
    );
  });

  test('debug runtime override can still enable mock', () {
    final compiled = AppConfig.fromEnvironment(
      isRelease: false,
      isWeb: true,
      useRestApi: 'true',
      appEnv: 'local',
      apiBaseUrl: '',
    );
    final mock =
        compiled.applyRuntimeOverride(isRelease: false, useRestApi: false);
    expect(mock.useRestApi, isFalse);
    expect(mock.demoMode, isTrue);
  });
}
