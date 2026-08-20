/// Real popup survey/poll — mirrors the backend's `surveys` +
/// `survey_options` shape exactly (`SurveyController::toJson()`).
class SurveyOption {
  final String id;
  final String label;
  final int votes;
  final double percentage;

  const SurveyOption({
    required this.id,
    required this.label,
    this.votes = 0,
    this.percentage = 0,
  });

  factory SurveyOption.fromJson(Map<String, dynamic> json) => SurveyOption(
        id: json['id'] as String,
        label: json['label'] as String,
        votes: json['votes'] as int? ?? 0,
        percentage: (json['percentage'] as num?)?.toDouble() ?? 0,
      );
}

class Survey {
  final String id;
  final String question;
  final String? description;
  final DateTime? startsAt;
  final DateTime? endsAt;
  final String targetAudience;
  final bool multipleChoice;
  final bool anonymous;
  final bool showResults;
  final bool active;
  final int totalVotes;

  /// Option ids the current (signed-in) user already voted for — empty
  /// means they haven't voted yet.
  final List<String> myOptionIds;
  final List<SurveyOption> options;

  const Survey({
    required this.id,
    required this.question,
    this.description,
    this.startsAt,
    this.endsAt,
    this.targetAudience = 'Tümü',
    this.multipleChoice = false,
    this.anonymous = true,
    this.showResults = true,
    this.active = true,
    this.totalVotes = 0,
    this.myOptionIds = const [],
    this.options = const [],
  });

  bool get hasVoted => myOptionIds.isNotEmpty;

  factory Survey.fromJson(Map<String, dynamic> json) => Survey(
        id: json['id'] as String,
        question: json['question'] as String,
        description: json['description'] as String?,
        startsAt: json['startsAt'] == null ? null : DateTime.tryParse(json['startsAt'] as String),
        endsAt: json['endsAt'] == null ? null : DateTime.tryParse(json['endsAt'] as String),
        targetAudience: json['targetAudience'] as String? ?? 'Tümü',
        multipleChoice: json['multipleChoice'] as bool? ?? false,
        anonymous: json['anonymous'] as bool? ?? true,
        showResults: json['showResults'] as bool? ?? true,
        active: json['active'] as bool? ?? true,
        totalVotes: json['totalVotes'] as int? ?? 0,
        myOptionIds: (json['myOptionIds'] as List<dynamic>?)?.cast<String>() ?? const [],
        options: (json['options'] as List<dynamic>?)
                ?.map((e) => SurveyOption.fromJson(e as Map<String, dynamic>))
                .toList() ??
            const [],
      );
}
