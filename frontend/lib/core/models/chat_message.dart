/// Mirrors the backend's `chat_messages` shape (`ChatController::messages()`).
/// Kept as a plain model file (no `shared_preferences`/Flutter dependency)
/// so `RestCampusRepository` and `tool/verify_rest_backend.dart` can use it
/// without pulling in platform-bound code they don't need.
class ChatMessage {
  final String id;
  final bool fromMe;
  final String text;
  final DateTime sentAt;

  ChatMessage({required this.id, required this.fromMe, required this.text, DateTime? sentAt})
      : sentAt = sentAt ?? DateTime.now();

  factory ChatMessage.fromJson(Map<String, dynamic> json) => ChatMessage(
        id: json['id'] as String,
        fromMe: json['fromMe'] as bool,
        text: json['text'] as String,
        sentAt: DateTime.parse(json['sentAt'] as String),
      );

  Map<String, dynamic> toJson() =>
      {'id': id, 'fromMe': fromMe, 'text': text, 'sentAt': sentAt.toIso8601String()};
}

/// Real conversation-list entry (docs/EKSIKLER.md sosyal/chat) — mirrors
/// `ChatController::threads()`'s real per-peer aggregate (last message,
/// when it was sent, real unread count), not just a bare peer name.
class ChatThreadSummary {
  final String peerName;
  final String? lastMessage;
  final DateTime? lastMessageAt;
  final int unreadCount;

  const ChatThreadSummary({
    required this.peerName,
    this.lastMessage,
    this.lastMessageAt,
    this.unreadCount = 0,
  });

  factory ChatThreadSummary.fromJson(Map<String, dynamic> json) => ChatThreadSummary(
        peerName: json['peerName'] as String,
        lastMessage: json['lastMessage'] as String?,
        lastMessageAt: json['lastMessageAt'] == null
            ? null
            : DateTime.tryParse(json['lastMessageAt'] as String),
        unreadCount: json['unreadCount'] as int? ?? 0,
      );
}
