import 'package:flutter/foundation.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/network/api_client.dart';

/// What the server says about this account's acceptance of the policy.
class PolicyConsentState {
  const PolicyConsentState({
    required this.required,
    required this.version,
    this.acceptedAt,
  });

  /// True when this account has not accepted the version now in force —
  /// either it never has, or the policy changed since it did.
  final bool required;

  final String version;
  final DateTime? acceptedAt;

  static PolicyConsentState? fromJson(Map<String, dynamic>? data) {
    if (data == null) return null;

    final version = data['version'];
    if (version is! String) return null;

    final accepted = data['acceptedAt'];

    return PolicyConsentState(
      required: data['required'] == true,
      version: version,
      acceptedAt: accepted is String ? DateTime.tryParse(accepted) : null,
    );
  }
}

/// Reads and records policy acceptance against the signed-in account.
///
/// The pre-sign-in gate stores a flag on the device, which is enough to stop
/// someone walking past the notice but is not a record of anything: it names
/// nobody, it has no version, and it is gone after a reinstall. This is the
/// record. It is also what re-opens the gate when the policy changes,
/// because only the server knows that it has.
class PolicyConsentClient {
  const PolicyConsentClient(this._api);

  final ApiClient _api;

  static const _path = '/api/v1/me/policy-consent';

  /// Whether this account still has to accept.
  ///
  /// Returns null when the question cannot be answered — offline, or the
  /// server is having a bad day. The caller must treat that as "don't know"
  /// and let the student through: locking someone out of the app because a
  /// consent check timed out is a worse failure than showing the notice one
  /// launch later.
  Future<PolicyConsentState?> fetch() async {
    try {
      final response = await _api.get(_path);
      return PolicyConsentState.fromJson(
        response['data'] as Map<String, dynamic>?,
      );
    } catch (_) {
      return null;
    }
  }

  /// Records that this account accepted what it was shown, in [language].
  ///
  /// The version is deliberately not sent. The server writes the version it
  /// is currently serving, so a stale client cannot record consent to a
  /// policy that is no longer in force.
  ///
  /// Returns false when it could not be recorded, so the caller can try
  /// again on the next launch rather than assuming it landed.
  Future<bool> accept(AppLanguage language) async {
    try {
      await _api.post(_path, body: {'locale': language.name});
      return true;
    } catch (_) {
      return false;
    }
  }
}

/// True while the signed-in account owes acceptance of an updated policy.
///
/// Global rather than passed down, for the same reason the moderation
/// notice handler is: the answer arrives from one place (a sync after sign
/// in) and is needed in another (the gate wrapped around the whole app),
/// and threading it through every screen between them is how it ends up
/// being checked in none of them.
final ValueNotifier<bool> policyReacceptanceRequired =
    ValueNotifier<bool>(false);

/// Brings the device's acknowledgement and the server's record into
/// agreement, once there is an account to attach the record to.
///
/// Three cases, and the middle one is the reason this exists:
///
///   * the server has no record and the device has accepted — post it, so
///     the acceptance survives the next reinstall;
///   * the server says a newer version is in force — reopen the gate;
///   * the check fails — do nothing. A student is not locked out of the app
///     because a request timed out.
Future<void> syncPolicyConsent(
  PolicyConsentClient client, {
  required AppLanguage language,
  required Future<bool> Function() deviceHasAccepted,
  required Future<void> Function() forgetDeviceAcceptance,
}) async {
  final state = await client.fetch();
  if (state == null) return;

  if (!state.required) {
    policyReacceptanceRequired.value = false;
    return;
  }

  if (await deviceHasAccepted()) {
    if (await client.accept(language)) {
      policyReacceptanceRequired.value = false;
      return;
    }
    // Could not record it. Leave the device flag alone and try again next
    // launch rather than showing the notice again over a network blip.
    return;
  }

  await forgetDeviceAcceptance();
  policyReacceptanceRequired.value = true;
}
