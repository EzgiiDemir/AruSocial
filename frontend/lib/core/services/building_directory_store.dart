import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../models/campus_models.dart';

/// On-device CRUD for the Building → Floor → Room → Person directory —
/// same real, working pattern as `AdminContentStore`, but with no seed data
/// (see `DirectoryEntry`'s doc comment for why). Entries only exist once an
/// admin actually adds them from the Admin Panel's "Bina Dizini" tab.
class BuildingDirectoryStore {
  static const _kEntries = 'admin.content.directory.v1';

  static Future<List<DirectoryEntry>> entries() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_kEntries);
    if (raw == null) return const [];
    return (jsonDecode(raw) as List)
        .map((e) => DirectoryEntry.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  static Future<void> saveEntry(DirectoryEntry entry) async {
    final current = await entries();
    final next = [
      for (final e in current) if (e.id != entry.id) e,
      entry,
    ];
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kEntries, jsonEncode(next.map((e) => e.toJson()).toList()));
  }

  static Future<void> deleteEntry(String id) async {
    final current = await entries();
    final next = current.where((e) => e.id != id).toList();
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kEntries, jsonEncode(next.map((e) => e.toJson()).toList()));
  }

  static Future<List<DirectoryEntry>> forService(String serviceId) async =>
      (await entries()).where((e) => e.relatedServiceId == serviceId).toList();
}
