import 'dart:convert';
import 'package:http/http.dart' as http;

class ModerationResult {
  final bool allowed;
  final List<String> reasons;
  const ModerationResult({required this.allowed, required this.reasons});
  factory ModerationResult.fromJson(Map<String, dynamic> json) => ModerationResult(
    allowed: json['allowed'] == true,
    reasons: List<String>.from(json['reasons'] ?? const []),
  );
}

class ContentModerationService {
  final String baseUrl;
  const ContentModerationService({required this.baseUrl});

  Future<ModerationResult> checkText(String text) async {
    final response = await http.post(
      Uri.parse('$baseUrl/api/moderate-text'),
      headers: {'Content-Type': 'application/json'},
      body: jsonEncode({'text': text}),
    );
    if (response.statusCode != 200) throw Exception('Moderasyon servisi kullanılamıyor.');
    return ModerationResult.fromJson(jsonDecode(response.body) as Map<String, dynamic>);
  }
}
