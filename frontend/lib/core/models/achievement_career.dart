/// Server-backed achievement definition + unlock state for the current user.
/// Distinct from [CampusUser.achievements] (free-text bio lines).
class Achievement {
  final String id;
  final String title;
  final String subtitle;
  final String triggerKind;
  final int threshold;
  final bool unlocked;
  final DateTime? unlockedAt;

  const Achievement({
    required this.id,
    required this.title,
    required this.subtitle,
    required this.triggerKind,
    required this.threshold,
    required this.unlocked,
    this.unlockedAt,
  });

  factory Achievement.fromJson(Map<String, dynamic> json) => Achievement(
        id: json['id'] as String,
        title: json['title'] as String,
        subtitle: json['subtitle'] as String? ?? '',
        triggerKind: json['triggerKind'] as String? ?? '',
        threshold: json['threshold'] as int? ?? 1,
        unlocked: json['unlocked'] as bool? ?? false,
        unlockedAt: json['unlockedAt'] != null
            ? DateTime.parse(json['unlockedAt'] as String)
            : null,
      );
}

class CareerOpportunity {
  final String id;
  final String title;
  final String kind;
  final String organization;
  final String? department;
  final String? url;
  final DateTime? deadline;
  final DateTime? postedAt;
  final String? description;
  final String? purpose;
  final String? skills;
  final String? experience;
  final String? education;
  final String? workType;
  final String? location;
  final String? extraInfo;
  final bool published;

  const CareerOpportunity({
    required this.id,
    required this.title,
    required this.kind,
    required this.organization,
    this.department,
    this.url,
    this.deadline,
    this.postedAt,
    this.description,
    this.purpose,
    this.skills,
    this.experience,
    this.education,
    this.workType,
    this.location,
    this.extraInfo,
    this.published = true,
  });

  factory CareerOpportunity.fromJson(Map<String, dynamic> json) =>
      CareerOpportunity(
        id: json['id'] as String,
        title: json['title'] as String,
        kind: json['kind'] as String,
        organization: json['organization'] as String? ?? '',
        department: json['department'] as String?,
        url: json['url'] as String?,
        deadline: json['deadline'] != null
            ? DateTime.parse(json['deadline'] as String)
            : null,
        postedAt: json['postedAt'] != null
            ? DateTime.parse(json['postedAt'] as String)
            : null,
        description: json['description'] as String?,
        purpose: json['purpose'] as String?,
        skills: json['skills'] as String?,
        experience: json['experience'] as String?,
        education: json['education'] as String?,
        workType: json['workType'] as String?,
        location: json['location'] as String?,
        extraInfo: json['extraInfo'] as String?,
        published: json['published'] as bool? ?? true,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'title': title,
        'kind': kind,
        'organization': organization,
        if (department != null) 'department': department,
        if (url != null) 'url': url,
        if (deadline != null)
          'deadline': deadline!.toIso8601String().split('T').first,
        if (postedAt != null)
          'postedAt': postedAt!.toIso8601String().split('T').first,
        if (description != null) 'description': description,
        if (purpose != null) 'purpose': purpose,
        if (skills != null) 'skills': skills,
        if (experience != null) 'experience': experience,
        if (education != null) 'education': education,
        if (workType != null) 'workType': workType,
        if (location != null) 'location': location,
        if (extraInfo != null) 'extraInfo': extraInfo,
        'published': published,
      };
}

class CareerProfile {
  final String? occupation;
  final String? expertise;
  final bool hasCv;
  final String? cvFileName;
  final bool lookingForInternships;
  final bool lookingForJobs;
  final DateTime? updatedAt;

  const CareerProfile({
    this.occupation,
    this.expertise,
    this.hasCv = false,
    this.cvFileName,
    this.lookingForInternships = false,
    this.lookingForJobs = false,
    this.updatedAt,
  });

  String? get headline => occupation;
  String? get cvUrl => hasCv ? cvFileName : null;

  factory CareerProfile.fromJson(Map<String, dynamic> json) => CareerProfile(
        occupation: (json['occupation'] as String?) ?? json['headline'] as String?,
        expertise: json['expertise'] as String?,
        hasCv: json['hasCv'] as bool? ??
            ((json['cvUrl'] as String?)?.isNotEmpty == true),
        cvFileName: json['cvFileName'] as String?,
        lookingForInternships: json['lookingForInternships'] as bool? ?? false,
        lookingForJobs: json['lookingForJobs'] as bool? ?? false,
        updatedAt: json['updatedAt'] != null
            ? DateTime.parse(json['updatedAt'] as String)
            : null,
      );
}

class CareerApplication {
  final String id;
  final String userId;
  final String? userName;
  final String? userEmail;
  final String opportunityId;
  final String? opportunityTitle;
  final bool hasCv;
  final String? cvFileName;
  final String status;
  final String? adminNotes;
  final DateTime? createdAt;

  const CareerApplication({
    required this.id,
    required this.userId,
    required this.opportunityId,
    required this.status,
    this.userName,
    this.userEmail,
    this.opportunityTitle,
    this.hasCv = false,
    this.cvFileName,
    this.adminNotes,
    this.createdAt,
  });

  factory CareerApplication.fromJson(Map<String, dynamic> json) =>
      CareerApplication(
        id: json['id'] as String,
        userId: '${json['userId'] ?? ''}',
        userName: json['userName'] as String?,
        userEmail: json['userEmail'] as String?,
        opportunityId: json['opportunityId'] as String,
        opportunityTitle: json['opportunityTitle'] as String?,
        hasCv: json['hasCv'] as bool? ?? false,
        cvFileName: json['cvFileName'] as String?,
        status: json['status'] as String,
        adminNotes: json['adminNotes'] as String?,
        createdAt: json['createdAt'] != null
            ? DateTime.tryParse(json['createdAt'] as String)
            : null,
      );
}

class ConsultationOffering {
  final String id;
  final String title;
  final String? purpose;
  final String? audience;
  final String? content;
  final String? outcomes;
  final String? duration;
  final String? format;
  final String? requirements;
  final String? counselorName;
  final String? counselorStaffId;
  final bool published;

  const ConsultationOffering({
    required this.id,
    required this.title,
    this.purpose,
    this.audience,
    this.content,
    this.outcomes,
    this.duration,
    this.format,
    this.requirements,
    this.counselorName,
    this.counselorStaffId,
    this.published = true,
  });

  factory ConsultationOffering.fromJson(Map<String, dynamic> json) =>
      ConsultationOffering(
        id: json['id'] as String,
        title: json['title'] as String,
        purpose: json['purpose'] as String?,
        audience: json['audience'] as String?,
        content: json['content'] as String?,
        outcomes: json['outcomes'] as String?,
        duration: json['duration'] as String?,
        format: json['format'] as String?,
        requirements: json['requirements'] as String?,
        counselorName: json['counselorName'] as String?,
        counselorStaffId: json['counselorStaffId'] as String?,
        published: json['published'] as bool? ?? true,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'title': title,
        if (purpose != null) 'purpose': purpose,
        if (audience != null) 'audience': audience,
        if (content != null) 'content': content,
        if (outcomes != null) 'outcomes': outcomes,
        if (duration != null) 'duration': duration,
        if (format != null) 'format': format,
        if (requirements != null) 'requirements': requirements,
        if (counselorName != null) 'counselorName': counselorName,
        if (counselorStaffId != null) 'counselorStaffId': counselorStaffId,
        'published': published,
      };
}

class ConsultationApplication {
  final String id;
  final String userId;
  final String? userName;
  final String consultationId;
  final String? consultationTitle;
  final String status;
  final String? notes;
  final String? adminNotes;
  final DateTime? createdAt;

  const ConsultationApplication({
    required this.id,
    required this.userId,
    required this.consultationId,
    required this.status,
    this.userName,
    this.consultationTitle,
    this.notes,
    this.adminNotes,
    this.createdAt,
  });

  factory ConsultationApplication.fromJson(Map<String, dynamic> json) =>
      ConsultationApplication(
        id: json['id'] as String,
        userId: '${json['userId'] ?? ''}',
        userName: json['userName'] as String?,
        consultationId: json['consultationId'] as String,
        consultationTitle: json['consultationTitle'] as String?,
        status: json['status'] as String,
        notes: json['notes'] as String?,
        adminNotes: json['adminNotes'] as String?,
        createdAt: json['createdAt'] != null
            ? DateTime.tryParse(json['createdAt'] as String)
            : null,
      );
}
