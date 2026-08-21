/// One real ARUCAD personnel record (docs/EKSIKLER.md aktivite/onay
/// workflow §8/§10) — mirrors `Admin\AcademicStaffController::index()`.
/// Backs both the department→approver auto-routing display and the admin
/// "E-posta" recipient picker.
class AcademicStaffMember {
  final String id;
  final String name;
  final String? email;
  final bool emailVerified;
  final String type;
  final String? faculty;
  final String? department;
  final String? title;
  final bool isDepartmentHead;
  final bool isFacultyDean;

  const AcademicStaffMember({
    required this.id,
    required this.name,
    required this.email,
    required this.emailVerified,
    required this.type,
    required this.faculty,
    required this.department,
    required this.title,
    required this.isDepartmentHead,
    required this.isFacultyDean,
  });

  factory AcademicStaffMember.fromJson(Map<String, dynamic> json) => AcademicStaffMember(
        id: json['id'] as String,
        name: json['name'] as String,
        email: json['email'] as String?,
        emailVerified: json['emailVerified'] as bool? ?? false,
        type: json['type'] as String,
        faculty: json['faculty'] as String?,
        department: json['department'] as String?,
        title: json['title'] as String?,
        isDepartmentHead: json['isDepartmentHead'] as bool? ?? false,
        isFacultyDean: json['isFacultyDean'] as bool? ?? false,
      );
}
