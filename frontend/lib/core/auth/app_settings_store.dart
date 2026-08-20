import 'package:shared_preferences/shared_preferences.dart';

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

  static Future<String> language() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_kLanguage) ?? 'TR';
  }

  static Future<void> setLanguage(String language) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kLanguage, language);
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
    return prefs.getBool(_kCheckInVisible) ?? true;
  }

  static Future<void> setCheckInVisible(bool visible) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_kCheckInVisible, visible);
  }

  /// A locally-chosen profile picture — either a preset avatar URL or a
  /// `data:` URI for a device-picked photo. Overrides the account's default
  /// avatar once set.
  static Future<String?> avatarUrl() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_kAvatarUrl);
  }

  static Future<void> setAvatarUrl(String url) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kAvatarUrl, url);
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
    return prefs.getString(_kLocationVisibility) ?? 'ghost';
  }

  static Future<void> setLocationVisibilityName(String name) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kLocationVisibility, name);
  }

  /// Separate from the visibility *level* above — whether "X is nearby
  /// right now" style prompts about this student are allowed to surface to
  /// others at all. Off by default.
  static Future<bool> nearbyDiscoverable() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(_kNearbyDiscoverable) ?? false;
  }

  static Future<void> setNearbyDiscoverable(bool value) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_kNearbyDiscoverable, value);
  }

  /// Gates whether Quests shows its "suggestions for you" section — turning
  /// personalization off means no personalized nudging.
  static Future<bool> personalization() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(_kPersonalization) ?? true;
  }

  static Future<void> setPersonalization(bool value) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_kPersonalization, value);
  }

  static const _kOnboardingDone = 'settings.onboarding.done';

  /// Which "First 30 Days" checklist items this student has ticked off.
  static Future<Set<String>> onboardingDone() async {
    final prefs = await SharedPreferences.getInstance();
    return (prefs.getStringList(_kOnboardingDone) ?? const []).toSet();
  }

  static Future<void> setOnboardingStepDone(String id, bool done) async {
    final prefs = await SharedPreferences.getInstance();
    final current = (prefs.getStringList(_kOnboardingDone) ?? const []).toSet();
    if (done) {
      current.add(id);
    } else {
      current.remove(id);
    }
    await prefs.setStringList(_kOnboardingDone, current.toList());
  }

  static const _kOnboardingStartedAt = 'settings.onboarding.startedAt';

  /// First time onboarding progress was ever read on this device — an
  /// honest proxy for "day 1", since there's no real enrollment-date field
  /// to detect a genuinely new student. Lazily recorded on first read
  /// rather than faked as a fixed date; used to decide whether the "First
  /// 30 Days" card should still surface on Home.
  static Future<DateTime> onboardingStartedAt() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_kOnboardingStartedAt);
    if (raw != null) {
      final parsed = DateTime.tryParse(raw);
      if (parsed != null) return parsed;
    }
    final now = DateTime.now();
    await prefs.setString(_kOnboardingStartedAt, now.toIso8601String());
    return now;
  }

  static const _kJoinedClubs = 'settings.clubs.joined';

  /// Clubs this student has tapped "Katıl" on — a real, persisted, local
  /// join state. There's no membership-roster backend to sync this to
  /// other students/devices, but on this device it's genuine, not a UI
  /// toggle that forgets itself on next launch.
  static Future<Set<String>> joinedClubs() async {
    final prefs = await SharedPreferences.getInstance();
    return (prefs.getStringList(_kJoinedClubs) ?? const []).toSet();
  }

  static Future<void> setClubJoined(String clubId, bool joined) async {
    final prefs = await SharedPreferences.getInstance();
    final current = (prefs.getStringList(_kJoinedClubs) ?? const []).toSet();
    if (joined) {
      current.add(clubId);
    } else {
      current.remove(clubId);
    }
    await prefs.setStringList(_kJoinedClubs, current.toList());
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
}
