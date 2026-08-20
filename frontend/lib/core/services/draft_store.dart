import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../models/content_block.dart';

class Draft {
  final DateTime savedAt;
  final List<ContentBlock> blocks;
  const Draft({required this.savedAt, required this.blocks});
}

/// Real autosave for the block editor — protects in-progress editing from
/// being lost if the app/page closes unexpectedly. Scoped honestly: this
/// only covers the block-editor screen itself (not the whole multi-field
/// entity-edit dialog around it), and only for content that already has an
/// id (new/unsaved content has nowhere stable to key a draft against).
class DraftStore {
  static String _key(String draftKey) => 'admin.draft.v1.$draftKey';

  static Future<void> save(String draftKey, List<ContentBlock> blocks) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(
        _key(draftKey),
        jsonEncode({
          'savedAt': DateTime.now().toIso8601String(),
          'blocks': blocksToJson(blocks),
        }));
  }

  static Future<Draft?> load(String draftKey) async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_key(draftKey));
    if (raw == null) return null;
    final map = jsonDecode(raw) as Map<String, dynamic>;
    return Draft(
        savedAt: DateTime.parse(map['savedAt'] as String),
        blocks: blocksFromJson(map['blocks']));
  }

  static Future<void> clear(String draftKey) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_key(draftKey));
  }
}
