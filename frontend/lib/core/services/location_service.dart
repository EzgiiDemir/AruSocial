import 'dart:async';

import 'package:geolocator/geolocator.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Outcome of [LocationService.checkAndRequestPermission] — distinguishes
/// "location services are off at the OS level" from the two denial states
/// Geolocator's own [LocationPermission] enum already has, since callers
/// (notably the app-wide startup prompt) want to tell the student which of
/// those actually happened.
enum LocationAccessStatus { granted, serviceDisabled, denied, deniedForever }

extension LocationAccessStatusX on LocationAccessStatus {
  bool get isGranted => this == LocationAccessStatus.granted;
}

/// Single home for the "is location on, do we have permission, get a fix"
/// sequence that used to be copy-pasted verbatim across five different
/// screens (app startup, Home, Explore, in-app navigation, Place check-in)
/// — each one trusted to silently treat every denial the same way. Centralizing
/// it means that guarantee only has to be correct once.
class LocationService {
  const LocationService();

  static const handledPrefsKey = 'location_permission_handled';
  static const softBannerPrefsKey = 'location_denied_soft_banner_shown';

  /// Fires when OS permission becomes granted so Home/Explore can attach a
  /// position without waiting for pull-to-refresh or a full rebuild.
  static final StreamController<void> _grantedController =
      StreamController<void>.broadcast();

  static Stream<void> get onGranted => _grantedController.stream;

  static void notifyGranted() {
    if (!_grantedController.isClosed) _grantedController.add(null);
  }

  Future<bool> hasGranted() async {
    final permission = await Geolocator.checkPermission();
    return permission == LocationPermission.whileInUse ||
        permission == LocationPermission.always;
  }

  Future<bool> isPermissionHandled() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(handledPrefsKey) ?? false;
  }

  Future<void> markPermissionHandled() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(handledPrefsKey, true);
  }

  Future<bool> wasSoftBannerShown() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(softBannerPrefsKey) ?? false;
  }

  Future<void> markSoftBannerShown() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(softBannerPrefsKey, true);
  }

  Future<LocationAccessStatus> checkAndRequestPermission({
    bool requestAgain = false,
  }) async {
    final serviceEnabled = await Geolocator.isLocationServiceEnabled();
    if (!serviceEnabled) return LocationAccessStatus.serviceDisabled;

    var permission = await Geolocator.checkPermission();
    if (permission == LocationPermission.denied) {
      if (!requestAgain && await isPermissionHandled()) {
        return LocationAccessStatus.denied;
      }
      await markPermissionHandled();
      permission = await Geolocator.requestPermission();
    }
    if (permission == LocationPermission.denied) {
      return LocationAccessStatus.denied;
    }
    if (permission == LocationPermission.deniedForever) {
      return LocationAccessStatus.deniedForever;
    }
    notifyGranted();
    return LocationAccessStatus.granted;
  }

  /// The most recent fix any part of the app has seen, and when it arrived.
  ///
  /// Home and Explore already track location live, so by the time someone
  /// taps "navigate" the app almost always knows where they are. Asking the
  /// GPS for a *fresh* high-accuracy fix at that moment can still cost
  /// several seconds — the screen would sit on a spinner re-learning
  /// something it was already told. Sharing the last fix here lets a caller
  /// start from it immediately and refine afterwards.
  static Position? _lastKnown;
  static DateTime? _lastKnownAt;

  /// Beyond this the cached fix is treated as a starting hint rather than
  /// the truth: still worth drawing immediately, but a fresh fix is fetched
  /// alongside it.
  static const lastKnownFreshFor = Duration(minutes: 2);

  static Position? get lastKnown => _lastKnown;

  static bool get lastKnownIsFresh =>
      _lastKnown != null &&
      _lastKnownAt != null &&
      DateTime.now().difference(_lastKnownAt!) < lastKnownFreshFor;

  static Position? _remember(Position? position) {
    if (position != null) {
      _lastKnown = position;
      _lastKnownAt = DateTime.now();
    }
    return position;
  }

  /// Null when permission isn't granted — callers already all treated a
  /// denial as "this one feature just doesn't show," never as an error.
  Future<Position?> getCurrentPosition({LocationSettings? settings}) async {
    final status = await checkAndRequestPermission();
    if (!status.isGranted) return null;
    return _remember(await Geolocator.getCurrentPosition(
      locationSettings:
          settings ?? const LocationSettings(accuracy: LocationAccuracy.medium),
    ));
  }

  /// One-shot fix without prompting — for Home/Explore after app-level grant.
  Future<Position?> getCurrentPositionIfGranted(
      {LocationSettings? settings}) async {
    if (!await hasGranted()) return null;
    return _remember(await Geolocator.getCurrentPosition(
      locationSettings:
          settings ?? const LocationSettings(accuracy: LocationAccuracy.medium),
    ));
  }

  /// A position to start drawing with *now*, without waiting on the GPS.
  ///
  /// Tries, in order: the fix the app already has, then the OS-level last
  /// known position (free — the platform hands over a cached value without
  /// powering up the radio). Returns null only when the app has genuinely
  /// never had a location, which is the one case worth waiting for.
  Future<Position?> lastKnownPosition() async {
    if (_lastKnown != null) return _lastKnown;
    if (!await hasGranted()) return null;
    try {
      return _remember(await Geolocator.getLastKnownPosition());
    } catch (_) {
      return null;
    }
  }

  Stream<Position> positionStream({LocationSettings? settings}) =>
      Geolocator.getPositionStream(
        locationSettings:
            settings ?? const LocationSettings(accuracy: LocationAccuracy.high),
      ).map((position) => _remember(position)!);
}
