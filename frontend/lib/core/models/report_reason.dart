import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';

/// Why a student is reporting something.
///
/// Mirrors the backend's `ReportReason` enum exactly, because the wire
/// value is what the server stores, counts and prioritises. Sending free
/// text — which is what every report screen used to do — produced a queue
/// nobody could sort, and a report in Turkish and the same report in
/// English counted as two unrelated things.
///
/// The order here is the order shown to the user: the reasons someone is
/// most likely to be reaching for come first, and `other` is last so it
/// is a fallback rather than the easy option.
enum ReportReason {
  harassment('harassment'),
  bullying('bullying'),
  hate('hate'),
  threat('threat'),
  sexualContent('sexual_content'),
  violence('violence'),
  personalInformation('personal_information'),
  impersonation('impersonation'),
  scam('scam'),
  spam('spam'),
  drugs('drugs'),
  selfHarm('self_harm'),
  other('other');

  /// The exact string the API expects. Never localise this.
  final String code;

  const ReportReason(this.code);

  String label(AppStrings strings) => strings.t('report_reason_$code');

  /// One line of help, so "harassment" and "bullying" are distinguishable
  /// to someone upset enough to be filing a report in the first place.
  String hint(AppStrings strings) => strings.t('report_reason_${code}_hint');

  /// Shown after submitting. Self-harm gets a different acknowledgement:
  /// someone reporting a friend in crisis should not be told "thanks, we
  /// will review the violation".
  bool get isWelfareConcern => this == ReportReason.selfHarm;
}
