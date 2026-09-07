/// A real, backend-delivered notification row — mirrors
/// `NotificationController::index()`. Named `InboxNotification` (matching
/// the backend's own `Notification as InboxNotification` alias) to avoid
/// any ambiguity with Flutter's unrelated local-notification APIs.
class InboxNotification {
  final String id;
  final String kind;
  final String title;
  final String body;
  final bool read;
  final DateTime? createdAt;
  final String? actorUserId;
  final String? actorName;
  final String? actorAvatarUrl;
  final Map<String, dynamic>? data;

  const InboxNotification({
    required this.id,
    required this.kind,
    required this.title,
    required this.body,
    this.read = false,
    this.createdAt,
    this.actorUserId,
    this.actorName,
    this.actorAvatarUrl,
    this.data,
  });

  factory InboxNotification.fromJson(Map<String, dynamic> json) =>
      InboxNotification(
        id: json['id'] as String,
        kind: json['kind'] as String,
        title: json['title'] as String,
        body: json['body'] as String,
        read: json['read'] as bool? ?? false,
        createdAt: json['createdAt'] == null
            ? null
            : DateTime.tryParse(json['createdAt'] as String),
        actorUserId: json['actorUserId'] as String?,
        actorName: json['actorName'] as String?,
        actorAvatarUrl: json['actorAvatarUrl'] as String?,
        data: json['data'] is Map
            ? Map<String, dynamic>.from(json['data'] as Map)
            : null,
      );
}

class FollowRequestPeer {
  final String id;
  final String name;
  final String? avatarUrl;

  const FollowRequestPeer({
    required this.id,
    required this.name,
    this.avatarUrl,
  });

  factory FollowRequestPeer.fromJson(Map<String, dynamic> json) =>
      FollowRequestPeer(
        id: '${json['id']}',
        name: json['name'] as String,
        avatarUrl: json['avatarUrl'] as String?,
      );
}
