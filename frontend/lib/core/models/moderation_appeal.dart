/// A student's appeal against a moderation decision about their content.
class ModerationAppeal {
  final String id;
  final String caseId;

  /// What was decided originally — `remove` or `hold`.
  final String originalDecision;

  /// `open` | `reviewing` | `upheld` | `overturned`.
  final String status;

  /// The moderator's explanation. Always present once decided: the whole
  /// point of an appeal is that the student is told why.
  final String? reviewNote;

  final DateTime? submittedAt;
  final DateTime? reviewedAt;

  const ModerationAppeal({
    required this.id,
    required this.caseId,
    required this.originalDecision,
    required this.status,
    this.reviewNote,
    this.submittedAt,
    this.reviewedAt,
  });

  bool get isDecided =>
      status == 'upheld' || status == 'overturned';

  bool get wasSuccessful => status == 'overturned';

  factory ModerationAppeal.fromJson(Map<String, dynamic> json) =>
      ModerationAppeal(
        id: json['id'] as String? ?? '',
        caseId: json['caseId'] as String? ?? '',
        originalDecision: json['originalDecision'] as String? ?? '',
        // An unrecognised status is treated as still open rather than as
        // decided — telling a student their appeal is finished when it is
        // not is the worse of the two mistakes.
        status: json['status'] as String? ?? 'open',
        reviewNote: json['reviewNote'] as String?,
        submittedAt: DateTime.tryParse(json['submittedAt'] as String? ?? ''),
        reviewedAt: DateTime.tryParse(json['reviewedAt'] as String? ?? ''),
      );
}
