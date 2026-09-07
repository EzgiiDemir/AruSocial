/// Stable event names for product analytics. The tracker is a no-op until
/// a Firebase Analytics / Mixpanel account is wired in `main.dart`.
class AnalyticsEvents {
  static const authSuccess = 'auth_success';
  static const authFailure = 'auth_failure';
  static const authLogout = 'auth_logout';
  static const locationPermission = 'location_permission';
  static const placeCheckIn = 'place_check_in';
  static const routeStarted = 'route_started';
  static const feedPostCreated = 'feed_post_created';
  static const photoAdded = 'photo_added';
  static const serviceContact = 'service_contact';
  static const askQuery = 'ask_query';
}
