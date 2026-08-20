/// One real join record for an event's admin-facing roster — mirrors
/// `Admin\EventController::participants()`. This is the actual "yoklama"
/// (attendance) list a club president/teacher reviews and approves.
class EventParticipant {
  final String id;
  final String? userId;
  final String? studentName;
  final String? studentEmail;
  final String? participationTypeLabel;
  final DateTime? joinedAt;
  final DateTime? formSubmittedAt;
  final DateTime? approvedAt;
  final String? approvedBy;

  const EventParticipant({
    required this.id,
    this.userId,
    this.studentName,
    this.studentEmail,
    this.participationTypeLabel,
    this.joinedAt,
    this.formSubmittedAt,
    this.approvedAt,
    this.approvedBy,
  });

  bool get isApproved => approvedAt != null;

  /// Whether this join is actually reviewable yet — the backend hard-gates
  /// approval on this (`FORM_NOT_SUBMITTED`), so the admin roster uses it
  /// to disable the approve action instead of letting it fail server-side.
  bool get isFormSubmitted => formSubmittedAt != null;

  factory EventParticipant.fromJson(Map<String, dynamic> json) => EventParticipant(
        id: json['id'] as String,
        userId: json['userId']?.toString(),
        studentName: json['studentName'] as String?,
        studentEmail: json['studentEmail'] as String?,
        participationTypeLabel: json['participationTypeLabel'] as String?,
        joinedAt: json['joinedAt'] == null ? null : DateTime.tryParse(json['joinedAt'] as String),
        formSubmittedAt: json['formSubmittedAt'] == null
            ? null
            : DateTime.tryParse(json['formSubmittedAt'] as String),
        approvedAt:
            json['approvedAt'] == null ? null : DateTime.tryParse(json['approvedAt'] as String),
        approvedBy: json['approvedBy'] as String?,
      );
}
