import 'dart:convert';
import 'dart:typed_data';

import 'package:shared_preferences/shared_preferences.dart';

import '../models/media_item.dart';

/// Real, on-device Media Library for **Mock** mode. Rest mode talks to
/// `GET/POST /media` via `CampusRepository` and stores files on Laravel's
/// public disk. This store is kept so `USE_REST_API=false` still works
/// offline; it is not the REST canonical source. Existing base64 blobs
/// already in SharedPreferences (`admin.media.items.v1`) are not auto-
/// migrated to the backend.
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
