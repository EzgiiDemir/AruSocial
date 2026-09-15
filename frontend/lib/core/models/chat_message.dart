/// One entry in `GET /chat/threads` — the peer's display name plus their
/// real `avatarUrl` when they've set one (`users.avatar_url`), so the
/// thread list/header can show an actual photo instead of always falling
/// back to a colored initial. Mute/archive/restrict mirror `chat_thread_prefs`.
class ChatThreadPeer {
  final String name;
  final String? avatarUrl;
  final String? lastMessage;
  final DateTime? lastMessageAt;
  final int unreadCount;
  final bool muted;
  final bool archived;
  final bool restricted;

  const ChatThreadPeer({
    required this.name,
    this.avatarUrl,
    this.lastMessage,
    this.lastMessageAt,
    this.unreadCount = 0,
    this.muted = false,
    this.archived = false,
    this.restricted = false,
  });

  factory ChatThreadPeer.fromJson(Map<String, dynamic> json) => ChatThreadPeer(
        name: json['name'] as String,
        avatarUrl: json['avatarUrl'] as String?,
        lastMessage: json['lastMessage'] as String?,
        lastMessageAt: json['lastMessageAt'] == null
            ? null
            : DateTime.tryParse(json['lastMessageAt'] as String),
        unreadCount: (json['unreadCount'] as num?)?.toInt() ?? 0,
        muted: json['muted'] as bool? ?? false,
        archived: json['archived'] as bool? ?? false,
        restricted: json['restricted'] as bool? ?? false,
      );
}

/// Flags for one peer from `GET /chat/prefs` or `POST /chat/prefs/toggle`.
class ChatThreadPrefState {
  final String peer;
  final bool muted;
  final bool archived;
  final bool restricted;

  const ChatThreadPrefState({
    required this.peer,
    this.muted = false,
    this.archived = false,
    this.restricted = false,
  });

  factory ChatThreadPrefState.fromJson(Map<String, dynamic> json) =>
      ChatThreadPrefState(
        peer: json['peer'] as String? ?? '',
        muted: json['muted'] as bool? ?? false,
        archived: json['archived'] as bool? ?? false,
        restricted: json['restricted'] as bool? ?? false,
      );
}

/// Full prefs map from `GET /chat/prefs` — keyed by peer display name.
class ChatThreadPrefs {
  final Map<String, ChatThreadPrefState> byPeer;

  const ChatThreadPrefs(this.byPeer);

  factory ChatThreadPrefs.fromJsonList(List<dynamic> items) {
    final map = <String, ChatThreadPrefState>{};
    for (final item in items) {
      final state = ChatThreadPrefState.fromJson(item as Map<String, dynamic>);
      if (state.peer.isNotEmpty) map[state.peer] = state;
    }
    return ChatThreadPrefs(map);
  }

  bool muted(String peer) => byPeer[peer]?.muted ?? false;
  bool archived(String peer) => byPeer[peer]?.archived ?? false;
  bool restricted(String peer) => byPeer[peer]?.restricted ?? false;
}

/// Group chat from `GET/POST /chat/groups`.
class ChatGroup {
  final String id;
  final String name;
  final List<String> members;
  final bool muted;
  final bool archived;

  const ChatGroup({
    required this.id,
    required this.name,
    this.members = const [],
    this.muted = false,
    this.archived = false,
  });

  factory ChatGroup.fromJson(Map<String, dynamic> json) {
    final rawMembers = json['members'];
    final names = <String>[];
    if (rawMembers is List) {
      for (final m in rawMembers) {
        if (m is String) {
          names.add(m);
        } else if (m is Map) {
          final name = m['name'] as String?;
          if (name != null && name.isNotEmpty) names.add(name);
        }
      }
    }
    return ChatGroup(
      id: '${json['id']}',
      name: json['name'] as String? ?? '',
      members: names,
      muted: json['muted'] as bool? ?? false,
      archived: json['archived'] as bool? ?? false,
    );
  }

  ChatGroup copyWith({
    String? id,
    String? name,
    List<String>? members,
    bool? muted,
    bool? archived,
  }) =>
      ChatGroup(
        id: id ?? this.id,
        name: name ?? this.name,
        members: members ?? this.members,
        muted: muted ?? this.muted,
        archived: archived ?? this.archived,
      );
}

/// One row from `GET /stories/{id}/viewers` (owner-only).
class StoryViewer {
  final String name;
  final String? avatarUrl;
  final DateTime? viewedAt;

  const StoryViewer({
    required this.name,
    this.avatarUrl,
    this.viewedAt,
  });

  factory StoryViewer.fromJson(Map<String, dynamic> json) => StoryViewer(
        name: json['name'] as String? ?? '',
        avatarUrl: json['avatarUrl'] as String?,
        viewedAt: json['viewedAt'] == null
            ? null
            : DateTime.tryParse(json['viewedAt'] as String),
      );
}

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
