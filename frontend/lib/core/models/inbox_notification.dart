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

  const InboxNotification({
    required this.id,
    required this.kind,
    required this.title,
    required this.body,
    this.read = false,
    this.createdAt,
  });

  factory InboxNotification.fromJson(Map<String, dynamic> json) => InboxNotification(
        id: json['id'] as String,
        kind: json['kind'] as String,
        title: json['title'] as String,
        body: json['body'] as String,
        read: json['read'] as bool? ?? false,
        createdAt:
            json['createdAt'] == null ? null : DateTime.tryParse(json['createdAt'] as String),
      );
}
