/// Mirrors the backend's chat message JSON (`ChatController::messages()`).
/// Kept as a plain model (no Flutter/prefs dependency) so REST repository
/// code and `tool/verify_rest_backend.dart` can share it.
class ChatMessage {
  final String id;
  final bool fromMe;
  final String text;
  final DateTime sentAt;
  final String? sender;
  final String? conversationId;

  ChatMessage({
    required this.id,
    required this.fromMe,
    required this.text,
    DateTime? sentAt,
    this.sender,
    this.conversationId,
  }) : sentAt = sentAt ?? DateTime.now();

  factory ChatMessage.fromJson(Map<String, dynamic> json, {String? myName}) {
    final sender = json['sender'] as String? ?? '';
    final fromMe = myName != null ? sender == myName : json['fromMe'] as bool;
    return ChatMessage(
      id: json['id'] as String,
      fromMe: fromMe,
      text: json['text'] as String,
      sentAt: DateTime.parse(json['sentAt'] as String),
      sender: sender.isEmpty ? null : sender,
      conversationId: json['conversationId']?.toString(),
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'fromMe': fromMe,
        'text': text,
        'sentAt': sentAt.toIso8601String(),
        if (sender != null) 'sender': sender,
        if (conversationId != null) 'conversationId': conversationId,
      };

  ChatMessage copyWith({
    String? id,
    bool? fromMe,
    String? text,
    DateTime? sentAt,
    String? sender,
    String? conversationId,
  }) =>
      ChatMessage(
        id: id ?? this.id,
        fromMe: fromMe ?? this.fromMe,
        text: text ?? this.text,
        sentAt: sentAt ?? this.sentAt,
        sender: sender ?? this.sender,
        conversationId: conversationId ?? this.conversationId,
      );
}
