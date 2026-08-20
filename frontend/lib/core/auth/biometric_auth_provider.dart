import 'package:flutter/foundation.dart';
import 'package:local_auth/local_auth.dart';

/// Real biometric authentication via the OS (Face ID / fingerprint), backed
/// by `local_auth`. There is no web implementation of this plugin — browsers
/// don't expose a fingerprint/Face ID API to web apps the way native OSes
/// do — so every method here degrades to "unavailable" on web instead of
/// throwing, rather than faking a result.
class BiometricAuthProvider {
  final LocalAuthentication _auth = LocalAuthentication();

  Future<bool> get isSupported async {
    if (kIsWeb) return false;
    try {
      final canCheck = await _auth.canCheckBiometrics;
      final deviceSupported = await _auth.isDeviceSupported();
      return canCheck && deviceSupported;
    } catch (_) {
      return false;
    }
  }

  /// The biometric types this specific device actually has enrolled/usable
  /// right now (e.g. face, fingerprint) — empty on web or unsupported
  /// hardware.
  Future<List<BiometricType>> availableBiometrics() async {
    if (kIsWeb) return const [];
    try {
      return await _auth.getAvailableBiometrics();
    } catch (_) {
      return const [];
    }
  }

  /// Returns true only if the device genuinely confirmed the user's
  /// fingerprint/face via the OS prompt.
  Future<bool> unlockWithBiometrics(
      {String reason = 'Kimliğini doğrula'}) async {
    if (kIsWeb) return false;
    try {
      final canCheck = await _auth.canCheckBiometrics;
      if (!canCheck) return false;

      final isAvailable = await _auth.isDeviceSupported();
      if (!isAvailable) return false;

      return await _auth.authenticate(
        localizedReason: reason,
        biometricOnly: true,
      );
    } catch (_) {
      return false;
    }
  }
}
