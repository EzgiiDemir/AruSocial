import 'package:shared_preferences/shared_preferences.dart';

/// Real, persisted "saved" (bookmark) state per post — same on-device
/// pattern as every other local preference in this app. Posts themselves
/// live in `MockCampusRepository` (in-memory), so this only tracks *which*
/// post ids the signed-in student has bookmarked, not the posts themselves.
class SavedPostsStore {
  static const _kSaved = 'social.saved_posts.v1';

  static Future<Set<String>> savedIds() async {
    final prefs = await SharedPreferences.getInstance();
    return (prefs.getStringList(_kSaved) ?? const []).toSet();
  }

  static Future<bool> toggle(String postId) async {
    final prefs = await SharedPreferences.getInstance();
    final current = (prefs.getStringList(_kSaved) ?? const []).toSet();
    final nowSaved = !current.contains(postId);
    if (nowSaved) {
      current.add(postId);
    } else {
      current.remove(postId);
    }
    await prefs.setStringList(_kSaved, current.toList());
    return nowSaved;
  }
}
