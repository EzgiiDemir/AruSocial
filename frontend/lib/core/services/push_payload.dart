class PushPayload {
  const PushPayload({
    required this.type,
    this.notificationId,
    this.conversationId,
    this.messageId,
    this.peer,
    this.title,
    this.body,
    this.route,
  });

  final String type;
  final String? notificationId;
  final String? conversationId;
  final String? messageId;
  final String? peer;
  final String? title;
  final String? body;
  final String? route;

  factory PushPayload.fromData(Map<String, dynamic> data, {String? title, String? body}) {
    String? read(String key) {
      final value = data[key];
      if (value == null) return null;
      final text = value.toString();
      return text.isEmpty ? null : text;
    }

    return PushPayload(
      type: read('type') ?? '',
      notificationId: read('notificationId'),
      conversationId: read('conversationId'),
      messageId: read('messageId'),
      peer: read('peer'),
      title: title,
      body: body,
      route: read('route'),
    );
  }

  bool get opensChat => type == 'message' && peer != null && peer!.isNotEmpty;
}
