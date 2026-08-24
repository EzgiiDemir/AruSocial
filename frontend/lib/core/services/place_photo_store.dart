import 'package:shared_preferences/shared_preferences.dart';

/// PlaceId → display src pointer (backend URL in Rest, data URI in Mock).
/// Not a second media database — the binary lives in `/media` storage
/// (Rest) or `MediaLibraryStore` (Mock). Legacy rows may still hold a
/// `data:` URI; new Rest uploads store the returned URL instead.
class PlacePhotoStore {
  static String _key(String placeId) => 'place.cover_photo.v1.$placeId';

  static Future<String?> photoFor(String placeId) async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getString(_key(placeId));
  }

  static Future<void> setPhoto(String placeId, String src) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_key(placeId), src);
  }
}
