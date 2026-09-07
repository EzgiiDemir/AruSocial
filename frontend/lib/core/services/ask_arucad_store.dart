import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

import '../auth/session_store.dart';

/// One turn in an Ask ARUCAD conversation.
class AskArucadMessage {
  final bool fromUser;
  final String text;
  final DateTime at;

  const AskArucadMessage({required this.fromUser, required this.text, required this.at});

  Map<String, dynamic> toJson() =>
      {'fromUser': fromUser, 'text': text, 'at': at.toIso8601String()};

  factory AskArucadMessage.fromJson(Map<String, dynamic> json) => AskArucadMessage(
        fromUser: json['fromUser'] as bool? ?? json['role'] == 'user',
        text: (json['text'] ?? json['content'] ?? '') as String,
        at: DateTime.tryParse(json['at'] as String? ?? '') ?? DateTime.now(),
      );
}

/// A single saved thread — title is derived from the first question asked.
class AskArucadConversation {
  final String id;
  String title;
  final List<AskArucadMessage> messages;
  DateTime updatedAt;

  AskArucadConversation({
    required this.id,
    required this.title,
    required this.messages,
    required this.updatedAt,
  });

  Map<String, dynamic> toJson() => {
        'id': id,
        'title': title,
        'messages': messages.map((m) => m.toJson()).toList(),
        'updatedAt': updatedAt.toIso8601String(),
      };

  factory AskArucadConversation.fromJson(Map<String, dynamic> json) => AskArucadConversation(
        id: json['id'] as String,
        title: json['title'] as String,
        messages: (json['messages'] as List<dynamic>? ?? const [])
            .map((m) => AskArucadMessage.fromJson(m as Map<String, dynamic>))
            .toList(),
        updatedAt: DateTime.tryParse(json['updatedAt'] as String? ?? '') ?? DateTime.now(),
      );

  AskArucadConversation withId(String newId) => AskArucadConversation(
        id: newId,
        title: title,
        messages: messages,
        updatedAt: updatedAt,
      );
}

/// Real, on-device conversation history for the Ask ARUCAD chat tab —
/// same SharedPreferences-backed pattern as every other local store in this
/// app. Genuinely persisted (survives app restarts), genuinely per-device
/// (no cross-device sync, since there's no backend — consistent with every
/// other "local-only, honestly scoped" store in this project).
class AskArucadStore {
  static const _keyPrefix = 'ask_arucad.conversations.v1';

  static Future<String> _key({String? ownerEmail}) async {
    final email = (ownerEmail ?? await SessionStore.email())?.trim().toLowerCase();
    if (email == null || email.isEmpty) return '$_keyPrefix.signed-out';
    return '$_keyPrefix.$email';
  }

  static Future<List<AskArucadConversation>> all({String? ownerEmail}) async {
    final prefs = await SharedPreferences.getInstance();
    final raw = prefs.getString(await _key(ownerEmail: ownerEmail));
    if (raw == null || raw.isEmpty) return [];
    final list = jsonDecode(raw) as List<dynamic>;
    final conversations = list
        .map((e) => AskArucadConversation.fromJson(e as Map<String, dynamic>))
        .toList();
    conversations.sort((a, b) => b.updatedAt.compareTo(a.updatedAt));
    return conversations;
  }

  static Future<void> save(AskArucadConversation conversation, {String? ownerEmail}) async {
    final prefs = await SharedPreferences.getInstance();
    final conversations = await all(ownerEmail: ownerEmail);
    conversations.removeWhere((c) => c.id == conversation.id);
    conversations.add(conversation);
    conversations.sort((a, b) => b.updatedAt.compareTo(a.updatedAt));
    await prefs.setString(
        await _key(ownerEmail: ownerEmail),
        jsonEncode(conversations.map((c) => c.toJson()).toList()));
  }

  static Future<void> delete(String id, {String? ownerEmail}) async {
    final prefs = await SharedPreferences.getInstance();
    final conversations = await all(ownerEmail: ownerEmail);
    conversations.removeWhere((c) => c.id == id);
    await prefs.setString(
        await _key(ownerEmail: ownerEmail),
        jsonEncode(conversations.map((c) => c.toJson()).toList()));
  }
}
