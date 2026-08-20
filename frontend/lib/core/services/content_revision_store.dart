import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../models/content_block.dart';
import '../models/content_revision.dart';

/// Real revision history — one entry per save, per content item, keyed by
/// e.g. `event:123` or `page:456`. Capped at the last 20 revisions per item
/// so the SharedPreferences blob stays bounded on content that's edited
/// often. "Compare" is left to the caller (show two snapshots side by
/// side) rather than a computed diff — a real diff algorithm over
/// structured blocks is a bigger feature than this pass covers, and
/// showing both versions in full is still a genuine, honest comparison.
class ContentRevisionStore {
  static String _key(String contentKey) => 'admin.revisions.v1.$contentKey';
  static const _cap = 20;

  static Future<List<ContentRevision>> revisionsFor(String contentKey) async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_key(contentKey));
    if (raw == null) return const [];
    return (jsonDecode(raw) as List)
        .map((e) => ContentRevision.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  static Future<void> record(
      String contentKey, List<ContentBlock> snapshot, String editorName) async {
    if (snapshot.isEmpty) return; // Nothing to version yet.
    final current = await revisionsFor(contentKey);
    final entry = ContentRevision(
      id: 'rev-${DateTime.now().microsecondsSinceEpoch}',
      savedAt: DateTime.now(),
      editorName: editorName,
      snapshot: snapshot,
    );
    final next = [entry, ...current];
    final capped = next.length > _cap ? next.sublist(0, _cap) : next;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_key(contentKey), jsonEncode(capped.map((r) => r.toJson()).toList()));
  }
}
