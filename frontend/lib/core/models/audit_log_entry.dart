/// Mirrors the backend's `admin_audit_log` shape (`AuditLogController::index()`).
/// Kept as a plain model file (no `shared_preferences`/Flutter dependency)
/// so `RestCampusRepository` and `tool/verify_rest_backend.dart` can use it
/// without pulling in platform-bound code they don't need.
class AuditLogEntry {
  final String id;
  final DateTime at;
  final String actorName;
  final String action;
  final String targetType;
  final String targetLabel;

  const AuditLogEntry({
    required this.id,
    required this.at,
    required this.actorName,
    required this.action,
    required this.targetType,
    required this.targetLabel,
  });

  factory AuditLogEntry.fromJson(Map<String, dynamic> json) => AuditLogEntry(
        id: json['id'] as String,
        at: DateTime.parse(json['at'] as String),
        actorName: json['actorName'] as String,
        action: json['action'] as String,
        targetType: json['targetType'] as String,
        targetLabel: json['targetLabel'] as String,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'at': at.toIso8601String(),
        'actorName': actorName,
        'action': action,
        'targetType': targetType,
        'targetLabel': targetLabel,
      };
}
