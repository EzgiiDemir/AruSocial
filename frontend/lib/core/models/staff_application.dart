/// Backend payload fields (question id -> answer) are conceptually always a
/// JSON object, but PHP's `json_encode` has no way to tell an empty
/// associative array from an empty list apart — an application with no
/// answers yet can legitimately arrive as `[]` instead of `{}`. Treat that
/// case as an empty map instead of throwing, rather than depending on every
/// backend call site getting the object-vs-array encoding right forever.
Map<String, dynamic> _asStringMap(dynamic value) {
  if (value is Map) return Map<String, dynamic>.from(value);
  return const {};
}

class StaffProfile {
  final String id;
  final String name;
  final String? faculty;
  final String? department;
  final String? title;
  final String? email;
  final bool isDepartmentHead;
  final bool active;
  /// Links this staff record to a real login-capable account — set this to
  /// provision someone as a Trainer (see Trainer Panel), otherwise null.
  final String? userId;

  const StaffProfile({
    required this.id,
    required this.name,
    this.faculty,
    this.department,
    this.title,
    this.email,
    this.isDepartmentHead = false,
    this.active = true,
    this.userId,
  });

  factory StaffProfile.fromJson(Map<String, dynamic> json) => StaffProfile(
        id: json['id'] as String,
        name: json['name'] as String,
        faculty: json['faculty'] as String?,
        department: json['department'] as String?,
        title: json['title'] as String?,
        email: json['email'] as String?,
        isDepartmentHead: json['isDepartmentHead'] as bool? ?? false,
        active: json['active'] as bool? ?? true,
        userId: json['userId'] as String?,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'faculty': faculty,
        'department': department,
        'title': title,
        'email': email,
        'isDepartmentHead': isDepartmentHead,
        'active': active,
        'userId': userId,
      };
}

/// Two-stage apply flow lifecycle — see docs/API_CONTRACT.md's
/// Applications section. `formPayload`/`previewPayload` (same data, kept
/// dual-named server-side for backward compatibility) is the short
/// Preview stage; `detailPayload` is the full Detail stage behind the
/// emailed link. Only [isApproved] means the student is a real
/// participant — every other status, including `detailFormSubmitted`/
/// `underReview`, must never show a "Katıldın"-style UI.
class ParticipationApplication {
  static const statusDetailFormPending = 'detail_form_pending';
  static const statusDetailFormSubmitted = 'detail_form_submitted';
  static const statusUnderReview = 'under_review';
  static const statusRevisionRequired = 'revision_required';
  static const statusApproved = 'approved';
  static const statusRejected = 'rejected';
  static const statusCancelled = 'cancelled';

  /// Statuses where the student doesn't yet have real participation but
  /// the application is still "in progress" — a fresh Preview submission
  /// for the same target must be blocked (`ALREADY_APPLIED`) while any of
  /// these are active.
  static const openStatuses = [
    statusDetailFormPending,
    statusDetailFormSubmitted,
    statusUnderReview,
    statusRevisionRequired,
  ];

  final String id;
  final String userId;
  final String? studentName;
  final String? studentDepartment;
  final String targetType;
  final String targetId;
  final String status;
  final String? responsibleStaffId;
  final String? responsibleStaffName;
  final Map<String, dynamic> formPayload;
  final Map<String, dynamic> detailPayload;
  final DateTime? detailFormSubmittedAt;
  final String? reviewNote;
  final DateTime? submittedAt;
  final String? detailFormUrl;
  final String? targetLabel;
  final bool emailSent;
  final String? lastEmailStatus;

  const ParticipationApplication({
    required this.id,
    required this.userId,
    required this.targetType,
    required this.targetId,
    required this.status,
    this.studentName,
    this.studentDepartment,
    this.responsibleStaffId,
    this.responsibleStaffName,
    this.formPayload = const {},
    this.detailPayload = const {},
    this.detailFormSubmittedAt,
    this.reviewNote,
    this.submittedAt,
    this.detailFormUrl,
    this.targetLabel,
    this.emailSent = false,
    this.lastEmailStatus,
  });

  bool get isApproved => status == statusApproved;
  bool get isOpen => openStatuses.contains(status);
  bool get needsRevision =>
      status == statusRevisionRequired || status == 'revision_requested';
  bool get canResubmitPreview =>
      status == statusRejected || status == statusCancelled;
  bool get awaitingStudentDetail =>
      status == statusDetailFormPending || needsRevision;
  bool get isDecidable =>
      status == statusUnderReview || status == statusDetailFormSubmitted;

  /// Whether a "Katıl" button should read as "Katıldın ✓" for this
  /// application — applying is now instant from the student's side (see
  /// `apply_bottom_sheet.dart`), so every non-rejected status counts as
  /// joined except `revision_required`: that one still needs the student
  /// to act (fix and resubmit via the emailed form), so showing a done
  /// checkmark there would hide that something is still expected of them.
  bool get countsAsJoined => isApproved || (isOpen && !needsRevision);

  String get statusLabel => switch (status) {
        statusApproved => 'Onaylandı',
        statusRejected => 'Reddedildi',
        statusCancelled => 'İptal edildi',
        statusUnderReview || statusDetailFormSubmitted => 'İncelemede',
        statusDetailFormPending => 'Detay formunu doldurun',
        statusRevisionRequired || 'revision_requested' => 'Revizyon istendi',
        _ => 'Beklemede',
      };

  factory ParticipationApplication.fromJson(Map<String, dynamic> json) =>
      ParticipationApplication(
        id: json['id'] as String,
        userId: '${json['userId']}',
        studentName: json['studentName'] as String?,
        studentDepartment: json['studentDepartment'] as String?,
        targetType: json['targetType'] as String,
        targetId: json['targetId'] as String,
        status: json['status'] as String,
        responsibleStaffId: json['responsibleStaffId'] as String?,
        responsibleStaffName: json['responsibleStaffName'] as String?,
        formPayload: _asStringMap(json['formPayload']),
        detailPayload: _asStringMap(json['detailPayload']),
        detailFormSubmittedAt: json['detailFormSubmittedAt'] != null
            ? DateTime.parse(json['detailFormSubmittedAt'] as String)
            : null,
        reviewNote: json['reviewNote'] as String?,
        submittedAt: json['submittedAt'] != null
            ? DateTime.parse(json['submittedAt'] as String)
            : null,
        detailFormUrl: json['detailFormUrl'] as String?,
        targetLabel: json['targetLabel'] as String?,
        emailSent: json['emailSent'] as bool? ?? false,
        lastEmailStatus: json['lastEmailStatus'] as String?,
      );
}

/// One admin-managed question behind a Preview or Detail form for a given
/// target type — see docs/API_CONTRACT.md's Applications section.
/// Rendered dynamically; never a hardcoded field list per category.
class ApplicationQuestion {
  final String id;
  final String targetType;
  final String stage;
  final String type;
  final String label;
  final String? helpText;
  final List<String> options;
  final bool required;
  final int sortOrder;
  final bool active;

  const ApplicationQuestion({
    required this.id,
    required this.targetType,
    required this.stage,
    required this.type,
    required this.label,
    this.helpText,
    this.options = const [],
    this.required = false,
    this.sortOrder = 0,
    this.active = true,
  });

  factory ApplicationQuestion.fromJson(Map<String, dynamic> json) => ApplicationQuestion(
        id: json['id'] as String,
        targetType: json['targetType'] as String,
        stage: json['stage'] as String,
        type: json['type'] as String,
        label: json['label'] as String,
        helpText: json['helpText'] as String?,
        options: (json['options'] as List? ?? []).map((e) => '$e').toList(),
        required: json['required'] as bool? ?? false,
        sortOrder: json['sortOrder'] as int? ?? 0,
        active: json['active'] as bool? ?? true,
      );
}

class AppointmentBooking {
  final String id;
  final String staffProfileId;
  final String? staffName;
  final String? staffDepartment;
  final String? studentName;
  final String date;
  final String startTime;
  final String endTime;
  final String status;
  final String? subject;
  final String? notes;
  final String? adminNotes;
  final DateTime? createdAt;

  const AppointmentBooking({
    required this.id,
    required this.staffProfileId,
    required this.date,
    required this.startTime,
    required this.endTime,
    required this.status,
    this.staffName,
    this.staffDepartment,
    this.studentName,
    this.subject,
    this.notes,
    this.adminNotes,
    this.createdAt,
  });

  factory AppointmentBooking.fromJson(Map<String, dynamic> json) =>
      AppointmentBooking(
        id: json['id'] as String,
        staffProfileId: json['staffProfileId'] as String,
        staffName: json['staffName'] as String?,
        staffDepartment: json['staffDepartment'] as String?,
        studentName: json['studentName'] as String?,
        date: json['date'] as String,
        startTime: json['startTime'] as String,
        endTime: json['endTime'] as String,
        status: json['status'] as String,
        subject: json['subject'] as String?,
        notes: json['notes'] as String?,
        adminNotes: json['adminNotes'] as String?,
        createdAt: json['createdAt'] != null
            ? DateTime.tryParse(json['createdAt'] as String)
            : null,
      );

  bool get canCancel => status == 'pending' || status == 'approved' || status == 'booked';
}

class StaffSlot {
  final String id;
  final String staffProfileId;
  final String date;
  final String startTime;
  final String endTime;
  final bool isBlocked;
  final bool available;
  /// Backend status: available | booked | unavailable | past
  final String status;

  const StaffSlot({
    required this.id,
    required this.staffProfileId,
    required this.date,
    required this.startTime,
    required this.endTime,
    this.isBlocked = false,
    this.available = true,
    this.status = 'available',
  });

  factory StaffSlot.fromJson(Map<String, dynamic> json) {
    final status = json['status'] as String? ??
        ((json['available'] as bool? ?? true) ? 'available' : 'unavailable');
    return StaffSlot(
      id: json['id'] as String,
      staffProfileId: json['staffProfileId'] as String,
      date: json['date'] as String,
      startTime: json['startTime'] as String,
      endTime: json['endTime'] as String,
      isBlocked: json['isBlocked'] as bool? ?? false,
      available: json['available'] as bool? ?? (status == 'available'),
      status: status,
    );
  }
}
