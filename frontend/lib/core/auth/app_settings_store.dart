import 'package:shared_preferences/shared_preferences.dart';

import 'session_store.dart';
import '../theme/arucad_theme.dart';

/// Which biometric method the user chose to enroll with. Stored only as a
/// label for the UI — the actual verification always goes through the OS's
/// own biometric prompt (`local_auth`), never a value we compare ourselves.
enum BiometricMethod { fingerprint, face }

/// Persists the small set of choices this prototype needs across app
/// restarts: language, and whether biometric sign-in has been enrolled for
/// an email. Backed by `shared_preferences` (works on web via localStorage).
class AppSettingsStore {
  static const _kLanguage = 'settings.language';
  static const _kBiometricEnabled = 'settings.biometric.enabled';
  static const _kBiometricMethod = 'settings.biometric.method';
  static const _kBiometricEmail = 'settings.biometric.email';
  static const _kCheckInVisible = 'settings.checkin.visible';
  static const _kAvatarUrl = 'settings.avatar.url';
  static const _kLocationVisibility = 'settings.location.visibility';
  static const _kNearbyDiscoverable = 'settings.location.nearbyDiscoverable';
  static const _kPersonalization = 'settings.personalization';
  static const _kPrivateProfile = 'settings.profile.private';
  static const _kThemePreference = 'settings.appearance.theme';
  static const _kPrivacyNoticeAck = 'settings.privacy_notice.acknowledged';

  /// Per-account local prefs so user B never inherits A’s avatar/onboarding.
  static Future<String> _accountKey(String base) async {
    final email = (await SessionStore.email())?.trim().toLowerCase();
    if (email == null || email.isEmpty) return base;
    return '$base.$email';
  }

  static Future<void> clearAccountLocalState() async {
    await disableBiometric();
  }

  static Future<String> language() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_kLanguage) ?? 'TR';
  }

  static Future<void> setLanguage(String language) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kLanguage, language);
  }

  /// Shown once before the sign-in screen, device-wide (not account-scoped
  /// — nobody has signed in yet when this is read).
  static Future<bool> privacyNoticeAcknowledged() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(_kPrivacyNoticeAck) ?? false;
  }

  static Future<void> setPrivacyNoticeAcknowledged() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_kPrivacyNoticeAck, true);
  }

  /// Appearance is intentionally device-wide rather than account-scoped:
  /// someone switching to dark mode before signing in should not be flashed a
  /// bright login screen, and the device's system option remains available.
  static Future<ArucadThemePreference> themePreference() async {
    final prefs = await SharedPreferences.getInstance();
    return ArucadThemePreference.fromStorage(
        prefs.getString(_kThemePreference));
  }

  static Future<void> setThemePreference(
      ArucadThemePreference preference) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kThemePreference, preference.name);
  }

  static Future<bool> biometricEnabled() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(_kBiometricEnabled) ?? false;
  }

  static Future<BiometricMethod?> biometricMethod() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_kBiometricMethod);
    for (final method in BiometricMethod.values) {
      if (method.name == raw) return method;
    }
    return null;
  }

  static Future<String?> biometricEmail() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_kBiometricEmail);
  }

  static Future<void> enableBiometric({
    required BiometricMethod method,
    required String email,
  }) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_kBiometricEnabled, true);
    await prefs.setString(_kBiometricMethod, method.name);
    await prefs.setString(_kBiometricEmail, email);
  }

  static Future<void> disableBiometric() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_kBiometricEnabled);
    await prefs.remove(_kBiometricMethod);
    await prefs.remove(_kBiometricEmail);
  }

  /// Whether a check-in should post to the social feed. Defaults to true
  /// (visible) — students who only want the XP, not the audience, can turn
  /// this off; the XP itself is never affected either way.
  static Future<bool> checkInVisible() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(await _accountKey(_kCheckInVisible)) ?? true;
  }

  static Future<void> setCheckInVisible(bool visible) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(await _accountKey(_kCheckInVisible), visible);
  }

  /// A locally-chosen profile picture — either a preset avatar URL or a
  /// `data:` URI for a device-picked photo. Overrides the account's default
  /// avatar once set.
  static Future<String?> avatarUrl() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(await _accountKey(_kAvatarUrl));
  }

  static Future<void> setAvatarUrl(String url) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(await _accountKey(_kAvatarUrl), url);
  }

  /// Real 4-level location visibility (Gizli/Arkadaşlarım/Topluluğum/Herkes)
  /// feeding the live map's default visibility — stored as the enum's
  /// `.name` rather than the `CampusVisibility` type itself, since that
  /// type lives in the feature layer and this file shouldn't depend on it;
  /// callers convert via `CampusVisibility.values.byName(...)`. Defaults to
  /// the most private option ("ghost") rather than defaulting someone into
  /// being visible.
  static Future<String> locationVisibilityName() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(await _accountKey(_kLocationVisibility)) ?? 'ghost';
  }

  static Future<void> setLocationVisibilityName(String name) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(await _accountKey(_kLocationVisibility), name);
  }

  /// Separate from the visibility *level* above — whether "X is nearby
  /// right now" style prompts about this student are allowed to surface to
  /// others at all. Off by default.
  static Future<bool> nearbyDiscoverable() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(await _accountKey(_kNearbyDiscoverable)) ?? false;
  }

  static Future<void> setNearbyDiscoverable(bool value) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(await _accountKey(_kNearbyDiscoverable), value);
  }

  /// Gates whether Quests shows its "suggestions for you" section — turning
  /// personalization off means no personalized nudging.
  static Future<bool> personalization() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(await _accountKey(_kPersonalization)) ?? true;
  }

  static Future<void> setPersonalization(bool value) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(await _accountKey(_kPersonalization), value);
  }

  static Future<bool> privateProfile() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(await _accountKey(_kPrivateProfile)) ?? false;
  }

  static Future<void> setPrivateProfile(bool value) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(await _accountKey(_kPrivateProfile), value);
  }

  static const _kOnboardingDone = 'settings.onboarding.done';

  /// Which "First 30 Days" checklist items this student has ticked off.
  /// Mock mode only — Rest mode reads `onboarding_progress` via
  /// `CampusRepository.getOnboardingProgress()`.
  static Future<Set<String>> onboardingDone() async {
    final prefs = await SharedPreferences.getInstance();
    return (prefs.getStringList(await _accountKey(_kOnboardingDone)) ??
            const [])
        .toSet();
  }

  static Future<void> setOnboardingStepDone(String id, bool done) async {
    final prefs = await SharedPreferences.getInstance();
    final key = await _accountKey(_kOnboardingDone);
    final current = (prefs.getStringList(key) ?? const []).toSet();
    if (done) {
      current.add(id);
    } else {
      current.remove(id);
    }
    await prefs.setStringList(key, current.toList());
  }

  static const _kOnboardingStartedAt = 'settings.onboarding.startedAt';

  /// First time onboarding progress was ever read on this device — an
  /// honest proxy for "day 1", since there's no real enrollment-date field
  /// to detect a genuinely new student. Lazily recorded on first read
  /// rather than faked as a fixed date; used to decide whether the "First
  /// 30 Days" card should still surface on Home.
  static Future<DateTime> onboardingStartedAt() async {
    final prefs = await SharedPreferences.getInstance();
    final key = await _accountKey(_kOnboardingStartedAt);
    final raw = prefs.getString(key);
    if (raw != null) {
      final parsed = DateTime.tryParse(raw);
      if (parsed != null) return parsed;
    }
    final now = DateTime.now();
    await prefs.setString(key, now.toIso8601String());
    return now;
  }

  static const _kJoinedClubs = 'settings.clubs.joined';

  /// Clubs this student has tapped "Katıl" on — a real, persisted, local
  /// join state. There's no membership-roster backend to sync this to
  /// other students/devices, but on this device it's genuine, not a UI
  /// toggle that forgets itself on next launch.
  static Future<Set<String>> joinedClubs() async {
    final prefs = await SharedPreferences.getInstance();
    return (prefs.getStringList(await _accountKey(_kJoinedClubs)) ?? const [])
        .toSet();
  }

  static Future<void> setClubJoined(String clubId, bool joined) async {
    final prefs = await SharedPreferences.getInstance();
    final key = await _accountKey(_kJoinedClubs);
    final current = (prefs.getStringList(key) ?? const []).toSet();
    if (joined) {
      current.add(clubId);
    } else {
      current.remove(clubId);
    }
    await prefs.setStringList(key, current.toList());
  }

  static const _kRememberedIdentifier = 'settings.auth.rememberedIdentifier';

  /// "Beni Hatırla" — only ever remembers the identifier (email/student
  /// number), never the password, so next launch pre-fills the field
  /// instead of silently skipping authentication.
  static Future<String?> rememberedIdentifier() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_kRememberedIdentifier);
  }

  static Future<void> setRememberedIdentifier(String? identifier) async {
    final prefs = await SharedPreferences.getInstance();
    if (identifier == null || identifier.isEmpty) {
      await prefs.remove(_kRememberedIdentifier);
    } else {
      await prefs.setString(_kRememberedIdentifier, identifier);
    }
  }

  static const _kRuntimeUseRest = 'settings.runtime.useRestApi';
  static const _kRuntimeApiHost = 'settings.runtime.apiHost';
  static const _kRuntimeApiPort = 'settings.runtime.apiPort';

  /// Login-screen override for Laravel REST. `null` means follow the
  /// compile-time `--dart-define` / release default.
  static Future<bool?> runtimeUseRestApi() async {
    final prefs = await SharedPreferences.getInstance();
    if (!prefs.containsKey(_kRuntimeUseRest)) return null;
    return prefs.getBool(_kRuntimeUseRest);
  }

  static Future<String> runtimeApiHost() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_kRuntimeApiHost) ?? '';
  }

  static Future<int> runtimeApiPort() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getInt(_kRuntimeApiPort) ?? 4000;
  }

  static Future<void> setRuntimeApi({
    required bool useRestApi,
    required String host,
    int port = 4000,
  }) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_kRuntimeUseRest, useRestApi);
    await prefs.setString(_kRuntimeApiHost, host.trim());
    await prefs.setInt(_kRuntimeApiPort, port);
  }
}
