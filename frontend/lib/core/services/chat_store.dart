import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../models/chat_message.dart';

export '../models/chat_message.dart' show ChatMessage, ChatThreadSummary;

/// Real, persisted per-peer message threads — but single-device only.
///
/// This prototype has no backend, socket, or push connection, so a message
/// "sent" here is genuinely saved and re-appears on next open — the send/
/// compose/read UI is real — but it is never actually delivered to another
/// person's device. There's no simulated auto-reply either: faking a
/// classmate's response would be dishonest. True real-time, two-way
/// messaging needs a server; it's tracked as a P0 gap in
/// docs/PUBLISH_READINESS.md rather than faked here.
class ChatStore {
  static String _key(String peer) => 'chat.thread.v1.$peer';

  static Future<List<ChatMessage>> messages(String peer) async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(_key(peer));
    if (raw == null) return const [];
    return (jsonDecode(raw) as List)
        .map((e) => ChatMessage.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  static Future<void> send(String peer, String text) async {
    final current = await messages(peer);
    current.add(ChatMessage(
        id: 'msg-${DateTime.now().microsecondsSinceEpoch}', fromMe: true, text: text));
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_key(peer), jsonEncode(current.map((m) => m.toJson()).toList()));
  }

  /// Which of [knownPeers] already have message history — used to build
  /// the thread list without showing every peer as a thread by default.
  static Future<List<String>> threadPeers(List<String> knownPeers) async {
    final prefs = await SharedPreferences.getInstance();
    return knownPeers.where((peer) => prefs.containsKey(_key(peer))).toList();
  }
}
