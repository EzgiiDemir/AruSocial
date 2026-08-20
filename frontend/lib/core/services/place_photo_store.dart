import 'package:shared_preferences/shared_preferences.dart';

/// Real, persisted cover photo per place — same `data:` URI pattern as the
/// avatar override in `AppSettingsStore`. This is what actually lets a
/// place's 360°-tour hero stop showing the generic gray placeholder once
/// someone has a real photo to put behind it.
class PlacePhotoStore {
  static String _key(String placeId) => 'place.cover_photo.v1.$placeId';

  static Future<String?> photoFor(String placeId) async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_key(placeId));
  }

  static Future<void> setPhoto(String placeId, String dataUri) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_key(placeId), dataUri);
  }
}
