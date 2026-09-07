import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../config/campus_life_config.dart';
import '../models/campus_models.dart';

/// On-device CRUD for the Building → Floor → Room → Person directory —
/// same real, working pattern as `AdminContentStore`. Mock mode seeds a
/// small campus hierarchy (mirrors backend DatabaseSeeder) so drill-down
/// works offline without inventing a separate buildings database.
class BuildingDirectoryStore {
  static const _kEntries = 'admin.content.directory.v1';
  static const _kSeeded = 'admin.content.directory.seeded.v1';

  static List<DirectoryEntry> _seedEntries() => const [
        DirectoryEntry(
          id: 'dir-1',
          building: 'A Blok',
          floor: '1',
          room: '104',
          occupantName: 'Öğrenci İşleri Ofisi',
          occupantRole: 'İdari Birim',
          relatedServiceId: 'student-affairs',
        ),
        DirectoryEntry(
          id: 'dir-2',
          building: 'A Blok',
          floor: '1',
          room: '110',
          occupantName: 'Kayıt Birimi',
          occupantRole: 'İdari',
        ),
        DirectoryEntry(
          id: 'dir-3',
          building: 'A Blok',
          floor: '2',
          room: '201',
          occupantName: 'Kariyer Ofisi',
          occupantRole: 'Career',
          relatedServiceId: 'career',
        ),
        DirectoryEntry(
          id: 'dir-4',
          building: 'Atelier',
          floor: 'Zemin',
          room: 'Studio A',
          occupantName: 'Tasarım Stüdyosu',
          occupantRole: 'Atölye',
        ),
        DirectoryEntry(
          id: 'dir-5',
          building: 'Atelier',
          floor: '1',
          room: 'Baskı Atölyesi',
          occupantName: 'Baskı Birimi',
          occupantRole: 'Atölye',
        ),
      ];

  static Future<void> _ensureSeed() async {
    final prefs = await SharedPreferences.getInstance();
    if (prefs.getBool(_kSeeded) == true) return;
    if (prefs.getString(_kEntries) != null) {
      await prefs.setBool(_kSeeded, true);
      return;
    }
    await prefs.setString(
        _kEntries, jsonEncode(_seedEntries().map((e) => e.toJson()).toList()));
    await prefs.setBool(_kSeeded, true);
  }

  static Future<List<DirectoryEntry>> entries() async {
    await _ensureSeed();
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_kEntries);
    if (raw == null) return const [];
    return (jsonDecode(raw) as List)
        .map((e) => DirectoryEntry.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  static Future<void> saveEntry(DirectoryEntry entry) async {
    await _ensureSeed();
    final current = await entries();
    final next = [
      for (final e in current) if (e.id != entry.id) e,
      entry,
    ];
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kEntries, jsonEncode(next.map((e) => e.toJson()).toList()));
  }

  static Future<void> deleteEntry(String id) async {
    await _ensureSeed();
    final current = await entries();
    final next = current.where((e) => e.id != id).toList();
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kEntries, jsonEncode(next.map((e) => e.toJson()).toList()));
  }

  static Future<List<DirectoryEntry>> forService(String serviceId) async =>
      (await entries())
          .where((e) =>
              e.relatedServiceId != null &&
              campusServiceIdsMatch(e.relatedServiceId!, serviceId))
          .toList();
}
