import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../models/chat_message.dart';

export '../models/chat_message.dart' show ChatMessage;

/// Device-local chat persistence for Mock mode.
///
/// Rows are stored per unordered name pair with an explicit sender, so the
/// same history is visible from both sides and [ChatMessage.fromMe] is
/// computed for whoever is currently looking — matching the REST contract.
/// There is still only one signed-in Mock user on a device.
class ChatStore {
  static const _prefix = 'chat.thread.v2.';

  static String _pairKey(String a, String b) {
    final names = [a, b]..sort();
    return '${names[0]}\u0001${names[1]}';
  }

  static String _prefsKey(String a, String b) => '$_prefix${_pairKey(a, b)}';

  static Future<List<ChatMessage>> messages(String me, String peer) async {
    final rows = await _load(me, peer);
    return rows
        .map((row) => ChatMessage(
              id: row['id'] as String,
              fromMe: row['sender'] == me,
              text: row['text'] as String,
              sentAt: DateTime.parse(row['sentAt'] as String),
            ))
        .toList();
  }

  static Future<ChatMessage> send(String me, String peer, String text) async {
    final rows = await _load(me, peer);
    final message = ChatMessage(
      id: 'msg-${DateTime.now().microsecondsSinceEpoch}',
      fromMe: true,
      text: text,
    );
    rows.add({
      'id': message.id,
      'sender': me,
      'text': text,
      'sentAt': message.sentAt.toIso8601String(),
    });
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_prefsKey(me, peer), jsonEncode(rows));
    return message;
  }

  static Future<List<String>> threadPeers(String me, List<String> knownPeers) async {
    final prefs = await SharedPreferences.getInstance();
    final peers = <String>{};
    for (final key in prefs.getKeys()) {
      if (!key.startsWith(_prefix)) continue;
      final pair = key.substring(_prefix.length).split('\u0001');
      if (pair.length != 2) continue;
      if (pair[0] == me) peers.add(pair[1]);
      if (pair[1] == me) peers.add(pair[0]);
    }
    if (knownPeers.isEmpty) return peers.toList();
    return knownPeers.where(peers.contains).toList();
  }

  static Future<List<Map<String, dynamic>>> _load(String me, String peer) async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_prefsKey(me, peer));
    if (raw == null) return [];
    return (jsonDecode(raw) as List).cast<Map<String, dynamic>>();
  }
}
