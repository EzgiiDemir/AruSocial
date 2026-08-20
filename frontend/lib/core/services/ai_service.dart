import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;

import '../network/auth_token_adapter.dart';

abstract class AiService {
  Future<String> sendPrompt(String prompt);
  Future<String> sendVoice(Uint8List audioBytes) async {
    return '';
  }
}

class MockAiService extends AiService {
  @override
  Future<String> sendPrompt(String prompt) async {
    await Future<void>.delayed(const Duration(milliseconds: 500));
    return 'Demo cevap: "$prompt"';
  }
}

class BackendAiService extends AiService {
  final String baseUrl;
  final AuthTokenAdapter? auth;

  BackendAiService({required this.baseUrl, this.auth});

  @override
  Future<String> sendPrompt(String prompt) async {
    final url = Uri.parse('$baseUrl/ai/query');
    final headers = <String, String>{'Content-Type': 'application/json'};
    if (auth != null) {
      final extra = await auth!.authorizeHeaders();
      headers.addAll(extra);
    }
    final res = await http.post(url,
        headers: headers, body: jsonEncode({'prompt': prompt}));
    if (res.statusCode != 200) throw Exception('AI query failed');
    final body = jsonDecode(res.body) as Map<String, dynamic>;
    return body['reply'] as String? ?? '';
  }
}
