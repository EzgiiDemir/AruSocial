/// A piece of content waiting on a human decision.
///
/// Cases are what the moderator queue is actually made of. They arrive
/// either because someone reported the content or because a classifier
/// was unsure, and until now they existed only in the API — the endpoints
/// worked end to end and nothing in the app could open them. Held content
/// with no screen to review it is indistinguishable from deleted content.
class ModerationCase {
  final String id;
  final String contentType;
  final String contentId;
  final String? authorId;

  /// `user_report` or the automatic path, so a moderator can tell a
  /// classifier's uncertainty from a person's complaint.
  final String source;

  /// Lower sorts first. Severity of the worst claim made, nudged by how
  /// many separate people made one.
  final int priority;
  final String status;
  final String? decision;

  /// What the system would do on its own. Advisory only — a
  /// recommendation a moderator cannot overrule is not a review.
  final String? recommendation;
  final int reportCount;
  final DateTime? createdAt;

  const ModerationCase({
    required this.id,
    required this.contentType,
    required this.contentId,
    required this.source,
    required this.priority,
    required this.status,
    required this.reportCount,
    this.authorId,
    this.decision,
    this.recommendation,
    this.createdAt,
  });

  bool get isOpen => status == 'open';

  factory ModerationCase.fromJson(Map<String, dynamic> json) => ModerationCase(
        id: (json['id'] ?? '').toString(),
        contentType: (json['contentType'] ?? '').toString(),
        contentId: (json['contentId'] ?? '').toString(),
        authorId: json['authorId']?.toString(),
        source: (json['source'] ?? '').toString(),
        priority: int.tryParse('${json['priority']}') ?? 100,
        status: (json['status'] ?? 'open').toString(),
        decision: json['decision']?.toString(),
        recommendation: json['recommendation']?.toString(),
        reportCount: int.tryParse('${json['reportCount']}') ?? 0,
        createdAt: DateTime.tryParse('${json['createdAt']}'),
      );
}

/// One report filed against the content in a case.
class ModerationCaseReport {
  final String id;
  final String reasonCode;
  final String description;
  final DateTime? reportedAt;

  const ModerationCaseReport({
    required this.id,
    required this.reasonCode,
    required this.description,
    this.reportedAt,
  });

  factory ModerationCaseReport.fromJson(Map<String, dynamic> json) =>
      ModerationCaseReport(
        id: (json['id'] ?? '').toString(),
        reasonCode: (json['reasonCode'] ?? '').toString(),
        description: (json['description'] ?? '').toString(),
        reportedAt: DateTime.tryParse('${json['reportedAt']}'),
      );
}

/// What a model said about this content, and which model said it.
///
/// Shown to the moderator because a score without its model and policy
/// version cannot be argued with later — and the thresholds behind it
/// have already been wrong once.
class ModerationSignal {
  final String action;
  final List<String> categories;
  final Map<String, double> scores;
  final String? model;
  final String? policyVersion;

  const ModerationSignal({
    required this.action,
    required this.categories,
    required this.scores,
    this.model,
    this.policyVersion,
  });

  factory ModerationSignal.fromJson(Map<String, dynamic> json) {
    final rawScores = json['scores'];
    return ModerationSignal(
      action: (json['action'] ?? '').toString(),
      categories: [
        for (final c in (json['categories'] as List? ?? [])) c.toString()
      ],
      scores: {
        if (rawScores is Map)
          for (final e in rawScores.entries)
            e.key.toString(): double.tryParse('${e.value}') ?? 0,
      },
      model: json['model']?.toString(),
      policyVersion: json['policyVersion']?.toString(),
    );
  }
}

/// A case opened up: the content, who complained, what the models saw,
/// and what this author has done before.
class ModerationCaseDetail {
  final ModerationCase summary;

  /// A short excerpt of the content itself. Never the media — a reviewer
  /// opens that through the authenticated review-file route.
  final String? preview;
  final List<ModerationCaseReport> reports;
  final List<ModerationSignal> signals;

  /// Prior confirmed violations. A decision made without history either
  /// punishes a first mistake too hard or lets a pattern continue.
  final Map<String, dynamic> authorHistory;

  const ModerationCaseDetail({
    required this.summary,
    required this.reports,
    required this.signals,
    required this.authorHistory,
    this.preview,
  });

  factory ModerationCaseDetail.fromJson(Map<String, dynamic> json) =>
      ModerationCaseDetail(
        summary: ModerationCase.fromJson(json),
        preview: json['preview']?.toString(),
        reports: [
          for (final r in (json['reports'] as List? ?? []))
            ModerationCaseReport.fromJson(Map<String, dynamic>.from(r as Map))
        ],
        signals: [
          for (final s in (json['signals'] as List? ?? []))
            ModerationSignal.fromJson(Map<String, dynamic>.from(s as Map))
        ],
        authorHistory: json['authorHistory'] is Map
            ? Map<String, dynamic>.from(json['authorHistory'] as Map)
            : const {},
      );
}

/// A student asking for a decision to be looked at again.
class ModerationAppealReview {
  final String id;
  final String caseId;
  final String userId;
  final String originalDecision;
  final String reason;
  final String status;
  final DateTime? submittedAt;

  const ModerationAppealReview({
    required this.id,
    required this.caseId,
    required this.userId,
    required this.originalDecision,
    required this.reason,
    required this.status,
    this.submittedAt,
  });

  factory ModerationAppealReview.fromJson(Map<String, dynamic> json) =>
      ModerationAppealReview(
        id: (json['id'] ?? '').toString(),
        caseId: (json['caseId'] ?? '').toString(),
        userId: (json['userId'] ?? '').toString(),
        originalDecision: (json['originalDecision'] ?? '').toString(),
        reason: (json['reason'] ?? '').toString(),
        status: (json['status'] ?? 'open').toString(),
        submittedAt: DateTime.tryParse('${json['submittedAt']}'),
      );
}
