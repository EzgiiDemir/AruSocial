/// One durable row from `GET /realtime/events` — mirrors
/// `RealtimeController::index()` exactly. [id] is the cursor clients
/// advance; [type] is the subscription key screens filter on.
class CampusRealtimeEvent {
  final int id;
  final String type;
  final String audience;
  final String? entityType;
  final String? entityId;
  final String? actorId;
  final Map<String, dynamic>? payload;
  final DateTime? createdAt;

  const CampusRealtimeEvent({
    required this.id,
    required this.type,
    required this.audience,
    this.entityType,
    this.entityId,
    this.actorId,
    this.payload,
    this.createdAt,
  });

  factory CampusRealtimeEvent.fromJson(Map<String, dynamic> json) => CampusRealtimeEvent(
        id: json['id'] as int,
        type: json['type'] as String,
        audience: json['audience'] as String,
        entityType: json['entityType'] as String?,
        entityId: json['entityId'] as String?,
        actorId: json['actorId'] as String?,
        payload: json['payload'] is Map<String, dynamic>
            ? json['payload'] as Map<String, dynamic>
            : null,
        createdAt: json['createdAt'] == null ? null : DateTime.tryParse(json['createdAt'] as String),
      );
}

class RealtimePoll {
  final List<CampusRealtimeEvent> events;
  final int cursor;
  const RealtimePoll({required this.events, required this.cursor});

  factory RealtimePoll.fromJson(Map<String, dynamic> json) => RealtimePoll(
        events: (json['events'] as List<dynamic>)
            .map((e) => CampusRealtimeEvent.fromJson(e as Map<String, dynamic>))
            .toList(),
        cursor: json['cursor'] as int,
      );
}
