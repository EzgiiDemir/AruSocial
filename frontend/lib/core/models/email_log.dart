/// A real, sent-or-failed email attempt — mirrors
/// `Api/Admin/EmailController::logs()`, backed by the real `email_logs`
/// table (every `EmailService::send()` call writes one of these).
class EmailLogEntry {
  final String id;
  final String toEmail;
  final String subject;
  final String template;
  final String status;
  final String? error;
  final int attempts;
  final DateTime? sentAt;

  const EmailLogEntry({
    required this.id,
    required this.toEmail,
    required this.subject,
    required this.template,
    required this.status,
    this.error,
    this.attempts = 1,
    this.sentAt,
  });

  factory EmailLogEntry.fromJson(Map<String, dynamic> json) => EmailLogEntry(
        id: json['id'] as String,
        toEmail: json['toEmail'] as String,
        subject: json['subject'] as String,
        template: json['template'] as String,
        status: json['status'] as String,
        error: json['error'] as String?,
        attempts: json['attempts'] as int? ?? 1,
        sentAt: json['sentAt'] == null ? null : DateTime.tryParse(json['sentAt'] as String),
      );
}
