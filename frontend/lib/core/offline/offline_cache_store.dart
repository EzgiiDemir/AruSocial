import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../auth/session_store.dart';

/// Last-known REST snapshots so feed/map still render after a network drop.
/// Not a sync engine: writes go to the server when online; this is read-only
/// fallback.
class OfflineCacheStore {
  static Future<String> _key(String name) async {
    final email = (await SessionStore.email())?.trim().toLowerCase() ?? 'anon';
    return 'offline.v1.$email.$name';
  }

  static Future<void> putJson(String name, Object value) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(await _key(name), jsonEncode(value));
  }

  static Future<List<dynamic>?> getList(String name) async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(await _key(name));
    if (raw == null || raw.isEmpty) return null;
    final decoded = jsonDecode(raw);
    return decoded is List ? decoded : null;
  }
}
