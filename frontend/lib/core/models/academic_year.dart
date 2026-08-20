/// Mirrors the backend's `academic_years` table
/// (`AcademicYearController::toJson()`) — exactly one row is ever active
/// at a time (enforced server-side on upsert).
class AcademicYear {
  final String id;
  final String label;
  final DateTime startsOn;
  final DateTime endsOn;
  final bool isActive;

  const AcademicYear({
    required this.id,
    required this.label,
    required this.startsOn,
    required this.endsOn,
    this.isActive = false,
  });

  factory AcademicYear.fromJson(Map<String, dynamic> json) => AcademicYear(
        id: json['id'] as String,
        label: json['label'] as String,
        startsOn: DateTime.parse(json['startsOn'] as String),
        endsOn: DateTime.parse(json['endsOn'] as String),
        isActive: json['isActive'] as bool? ?? false,
      );
}
