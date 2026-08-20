import 'dart:convert';
import 'dart:typed_data';

import 'package:shared_preferences/shared_preferences.dart';

import '../models/media_item.dart';

/// Real, on-device Media Library — same `SharedPreferences`-backed pattern
/// as `AdminContentStore`, one JSON blob of every uploaded item. Images
/// only for now: this repo has no real object storage (S3/Cloud Storage)
/// to host video/large files, and stuffing large binaries into
/// SharedPreferences doesn't scale — see docs/PUBLISH_READINESS.md.
class MediaLibraryStore {
  static const _kItems = 'admin.media.items.v1';

  static Future<List<MediaItem>> items() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_kItems);
    if (raw == null) return const [];
    return (jsonDecode(raw) as List)
        .map((e) => MediaItem.fromJson(e as Map<String, dynamic>))
        .toList()
      ..sort((a, b) => b.uploadedAt.compareTo(a.uploadedAt));
  }

  static Future<void> _saveAll(List<MediaItem> items) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_kItems, jsonEncode(items.map((m) => m.toJson()).toList()));
  }

  static Future<MediaItem> upload(
    Uint8List bytes, {
    required String fileName,
    required String uploadedBy,
  }) async {
    final item = MediaItem(
      id: 'media-${DateTime.now().microsecondsSinceEpoch}',
      dataUri: 'data:image/jpeg;base64,${base64Encode(bytes)}',
      fileName: fileName,
      uploadedAt: DateTime.now(),
      uploadedBy: uploadedBy,
    );
    final current = await items();
    await _saveAll([...current, item]);
    return item;
  }

  static Future<void> rename(String id, String newFileName) async {
    final current = await items();
    await _saveAll([
      for (final m in current) if (m.id == id) m.copyWith(fileName: newFileName) else m,
    ]);
  }

  static Future<void> delete(String id) async {
    final current = await items();
    await _saveAll(current.where((m) => m.id != id).toList());
  }

  /// Tags an existing media item as used by [ref] (e.g. `event:123`) — a
  /// best-effort breadcrumb, not an enforced relation.
  static Future<void> markUsed(String id, String ref) async {
    final current = await items();
    await _saveAll([
      for (final m in current)
        if (m.id == id && !m.usedIn.contains(ref))
          m.copyWith(usedIn: [...m.usedIn, ref])
        else
          m,
    ]);
  }
}
