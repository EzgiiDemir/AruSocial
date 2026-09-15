import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/legal/policy_consent.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';

/// Reconciling the device's "I ticked the box" with the server's record of
/// who actually accepted what.
///
/// The device flag alone is not consent: it names nobody, it has no version,
/// and it is gone after a reinstall. The server record alone cannot gate the
/// login screen, because there is no account yet when that screen is shown.
/// So the two have to be brought into agreement once — and the failure modes
/// of doing that are what these tests are about.
void main() {
  late bool deviceAccepted;
  late bool forgotten;

  setUp(() {
    deviceAccepted = false;
    forgotten = false;
    policyReacceptanceRequired.value = false;
  });

  tearDown(() => policyReacceptanceRequired.value = false);

  PolicyConsentClient clientThat({
    required bool consentRequired,
    bool getFails = false,
    bool postFails = false,
    List<String>? postedLocales,
  }) {
    final api = ApiClient(
      baseUrl: 'https://api.test',
      client: MockClient((request) async {
        if (request.method == 'POST') {
          if (postFails) return http.Response('nope', 500);
          postedLocales?.add(
            (jsonDecode(request.body) as Map)['locale'] as String,
          );
          return http.Response(
            jsonEncode({
              'data': {
                'document': 'privacy',
                'version': 'privacy-abc123',
                'required': false,
                'acceptedAt': '2026-09-14T10:00:00+00:00',
              },
              'meta': {},
              'error': null,
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }

        if (getFails) return http.Response('nope', 500);

        return http.Response(
          jsonEncode({
            'data': {
              'document': 'privacy',
              'version': 'privacy-abc123',
              'required': consentRequired,
              'acceptedAt': consentRequired ? null : '2026-09-01T10:00:00Z',
            },
            'meta': {},
            'error': null,
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }),
    );

    return PolicyConsentClient(api);
  }

  Future<void> sync(PolicyConsentClient client,
          {AppLanguage language = AppLanguage.tr}) =>
      syncPolicyConsent(
        client,
        language: language,
        deviceHasAccepted: () async => deviceAccepted,
        forgetDeviceAcceptance: () async => forgotten = true,
      );

  // ---- the ordinary path ----------------------------------------------

  test('an account that already accepted is left alone', () async {
    deviceAccepted = true;

    await sync(clientThat(consentRequired: false));

    expect(policyReacceptanceRequired.value, isFalse);
    expect(forgotten, isFalse);
  });

  /// The reinstall case. The student accepted on this device just now, the
  /// server has never heard of them, and the acceptance has to be written
  /// down before it is lost again.
  test('an acceptance held only on the device is posted to the server',
      () async {
    deviceAccepted = true;
    final posted = <String>[];

    await sync(
      clientThat(consentRequired: true, postedLocales: posted),
      language: AppLanguage.ru,
    );

    expect(posted, ['ru'], reason: 'The language read should be recorded.');
    expect(policyReacceptanceRequired.value, isFalse);
    expect(forgotten, isFalse);
  });

  // ---- the case this exists for ---------------------------------------

  test('an updated policy reopens the gate', () async {
    deviceAccepted = false;

    await sync(clientThat(consentRequired: true));

    expect(policyReacceptanceRequired.value, isTrue);
    expect(forgotten, isTrue,
        reason: 'The stale device flag has to be cleared, or the gate '
            'closes again on the next launch.');
  });

  // ---- failure must not lock anyone out --------------------------------

  test('a failed check changes nothing', () async {
    deviceAccepted = true;

    await sync(clientThat(consentRequired: true, getFails: true));

    expect(policyReacceptanceRequired.value, isFalse);
    expect(forgotten, isFalse);
  });

  /// If the record could not be written, the student has still accepted on
  /// this device. Showing the notice again over a network blip trains
  /// people to dismiss it without reading, which is the opposite of what
  /// the screen is for.
  test('a failed post leaves the device acceptance in place', () async {
    deviceAccepted = true;

    await sync(clientThat(consentRequired: true, postFails: true));

    expect(forgotten, isFalse);
    expect(policyReacceptanceRequired.value, isFalse);
  });

  // ---- parsing ---------------------------------------------------------

  test('a malformed payload is treated as no answer', () {
    expect(PolicyConsentState.fromJson(null), isNull);
    expect(PolicyConsentState.fromJson({'required': true}), isNull,
        reason: 'Without a version there is nothing to record consent to.');
  });

  test('a well-formed payload is read', () {
    final state = PolicyConsentState.fromJson({
      'required': false,
      'version': 'privacy-abc123',
      'acceptedAt': '2026-09-01T10:00:00Z',
    });

    expect(state, isNotNull);
    expect(state!.required, isFalse);
    expect(state.version, 'privacy-abc123');
    expect(state.acceptedAt, isNotNull);
  });
}
