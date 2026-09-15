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
///
/// Personal gallery uses a separate prefs key so admin library and user
/// album never mix (mirrors backend `media_items.user_id`).
class MediaLibraryStore {
  static const _kItems = 'admin.media.items.v1';
  static const _kPersonal = 'user.gallery.items.v1';

  static String _mimeFor(String fileName) {
    final lower = fileName.toLowerCase();
    if (lower.endsWith('.png')) return 'image/png';
    if (lower.endsWith('.webp')) return 'image/webp';
    if (lower.endsWith('.gif')) return 'image/gif';
    return 'image/jpeg';
  }


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

  static Future<List<MediaItem>> personalItems() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_kPersonal);
    if (raw == null) return const [];
    return (jsonDecode(raw) as List)
        .map((e) => MediaItem.fromJson(e as Map<String, dynamic>))
        .toList()
      ..sort((a, b) => b.uploadedAt.compareTo(a.uploadedAt));
  }

  static Future<void> _savePersonal(List<MediaItem> items) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(
        _kPersonal, jsonEncode(items.map((m) => m.toJson()).toList()));
  }

  static Future<MediaItem> upload(
    Uint8List bytes, {
    required String fileName,
    required String uploadedBy,
  }) async {
    final mime = _mimeFor(fileName);

    const prefix = 'data:image/jpeg;base64,';
    final item = MediaItem(
      id: 'media-${DateTime.now().microsecondsSinceEpoch}',
      dataUri: '$prefix${base64Encode(bytes)}',
      fileName: fileName,
      uploadedAt: DateTime.now(),
      uploadedBy: uploadedBy,
      mimeType: mime,
      moderationStatus: 'approved',
    );
    final current = await items();
    await _saveAll([...current, item]);
    return item;
  }

  static Future<MediaItem> uploadPersonal(
    Uint8List bytes, {
    required String fileName,
    required String uploadedBy,
  }) async {
    final mime = _mimeFor(fileName);

    const prefix = 'data:image/jpeg;base64,';
    final item = MediaItem(
      id: 'media-${DateTime.now().microsecondsSinceEpoch}',
      dataUri: '$prefix${base64Encode(bytes)}',
      fileName: fileName,
      uploadedAt: DateTime.now(),
      uploadedBy: uploadedBy,
      mimeType: mime,
      moderationStatus: 'approved',
    );
    final current = await personalItems();
    await _savePersonal([...current, item]);
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

  static Future<void> deletePersonal(String id) async {
    final current = await personalItems();
    await _savePersonal(current.where((m) => m.id != id).toList());
  }

  static Future<void> setModerationStatus(String id, String status) async {
    final current = await items();
    await _saveAll([
      for (final m in current)
        if (m.id == id) m.copyWith(moderationStatus: status) else m,
    ]);
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
