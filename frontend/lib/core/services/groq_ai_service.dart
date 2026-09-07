import 'dart:convert';

import 'package:http/http.dart' as http;

import '../config/campus_life_config.dart';
import '../config/campus_sites.dart';
import '../config/shuttle_config.dart';
import '../models/campus_models.dart';

/// Backstop against stray markdown in an AI answer — the system prompt
/// below already forbids it, but a model doesn't always obey. Shared by
/// every place that renders a raw Ask ARUCAD answer (this service's
/// direct-Groq calls, and the real backend's own `AiController::query`,
/// which applies the equivalent strip server-side in PHP).
String stripAskArucadMarkdown(String text) {
  String unwrap(String input, RegExp pattern) =>
      input.replaceAllMapped(pattern, (m) => m.group(1) ?? '');

  var result = text;
  result = unwrap(result, RegExp(r'\*\*(.*?)\*\*', dotAll: true));
  result = unwrap(result, RegExp(r'\*(.*?)\*', dotAll: true));
  result = result.replaceAll(RegExp(r'^#{1,6}\s*', multiLine: true), '');
  result = result.replaceAll(RegExp(r'^[-*]\s+', multiLine: true), '');
  result = unwrap(result, RegExp(r'`{1,3}([^`]*)`{1,3}', dotAll: true));
  return result.trim();
}

String _groqMessageText(Map<String, dynamic> message) {
  final content = (message['content'] as String?)?.trim() ?? '';
  if (content.isNotEmpty) return content;
  return (message['reasoning'] as String?)?.trim() ?? '';
}

/// Calls Groq's OpenAI-compatible chat completions endpoint so Galatea can
/// give real, generated answers instead of the canned mock ones.
///
/// Two things worth knowing about this integration:
/// - The key is read from `--dart-define=GROQ_API_KEY=...` at build time,
///   never hardcoded in source — a hardcoded key ships inside the compiled
///   web bundle/APK, readable by anyone (browser network tab, `strings` on
///   the APK). It's still visible in the built artifact either way; the
///   real fix for production is `USE_REST_API=true` (see main.dart), which
///   routes this call through backend/'s `/api/v1/ai/query` proxy instead —
///   see docs/EXTERNAL_ACCOUNTS.md §3. Without a define and without
///   USE_REST_API, [ask]/[askConversation] throw immediately rather than
///   silently failing against an empty key.
/// - Some LLM APIs block direct browser calls with CORS (this project hit
///   exactly that wall with Google's Directions API). If this call fails in
///   the browser console with a CORS error, Groq needs to be called from a
///   server too; the fallback below keeps Galatea working with the mock
///   answers either way.
class GroqAiService {
  static const _apiKey = String.fromEnvironment('GROQ_API_KEY');
  static const _endpoint = 'https://api.groq.com/openai/v1/chat/completions';
  // llama-3.3-70b-versatile was deprecated by Groq on 2026-06-17.
  // llama-3.1-8b-instant is not on every Groq key. gpt-oss-20b is, but it
  // is a reasoning model — read `content`, then fall back to `reasoning`.
  static const _model = 'openai/gpt-oss-20b';

  /// [clubs]/[sports]/[services] should come from `AdminContentStore` (the
  /// live, admin-editable data), not the static seed consts, so the
  /// assistant's answers reflect whatever an admin has actually published —
  /// this is the "app'in tüm kaynaklara erişimi olsun" requirement: every
  /// real domain the app knows about (places, events, clubs, sports,
  /// services, shuttle, 360 campuses) is in context on every question, not
  /// just places/events.
  Future<String> ask(
    String prompt, {
    required List<CampusPlace> places,
    required List<CampusEvent> events,
    List<CampusClub> clubs = const [],
    List<CampusSport> sports = const [],
    List<CampusService> services = const [],
    List<CampusFoodVenue> foodVenues = const [],
  }) async {
    if (_apiKey.isEmpty) {
      throw Exception(
          'GROQ_API_KEY not provided (--dart-define) and USE_REST_API is off — nothing to call.');
    }
    final res = await http
        .post(
          Uri.parse(_endpoint),
          headers: {
            'Authorization': 'Bearer $_apiKey',
            'Content-Type': 'application/json',
          },
          body: jsonEncode({
            'model': _model,
            'messages': [
              {
                'role': 'system',
                'content':
                    _systemPrompt(places, events, clubs, sports, services, foodVenues),
              },
              {'role': 'user', 'content': prompt},
            ],
            'temperature': 0.4,
            'max_tokens': 800,
          }),
        )
        .timeout(const Duration(seconds: 25));

    if (res.statusCode != 200) {
      throw Exception('Groq ${res.statusCode}: ${res.body}');
    }
    final data =
        jsonDecode(utf8.decode(res.bodyBytes)) as Map<String, dynamic>;
    final choices = data['choices'] as List<dynamic>;
    final message = choices.first['message'] as Map<String, dynamic>;
    return stripAskArucadMarkdown(_groqMessageText(message));
  }

  /// Same real Groq call as [ask], but threads the whole conversation so
  /// far into the request — genuine multi-turn context (the model actually
  /// sees earlier turns), not just a single isolated question each time.
  /// Used by the full "Ask ARUCAD" chat tab; the quick map sheet still uses
  /// [ask] since it's a single-question-at-a-time surface by design.
  Future<String> askConversation(
    List<({bool fromUser, String text})> history, {
    required List<CampusPlace> places,
    required List<CampusEvent> events,
    List<CampusClub> clubs = const [],
    List<CampusSport> sports = const [],
    List<CampusService> services = const [],
    List<CampusFoodVenue> foodVenues = const [],
  }) async {
    if (_apiKey.isEmpty) {
      throw Exception(
          'GROQ_API_KEY not provided (--dart-define) and USE_REST_API is off — nothing to call.');
    }
    final res = await http
        .post(
          Uri.parse(_endpoint),
          headers: {
            'Authorization': 'Bearer $_apiKey',
            'Content-Type': 'application/json',
          },
          body: jsonEncode({
            'model': _model,
            'messages': [
              {
                'role': 'system',
                'content':
                    _systemPrompt(places, events, clubs, sports, services, foodVenues),
              },
              for (final turn in history)
                {'role': turn.fromUser ? 'user' : 'assistant', 'content': turn.text},
            ],
            'temperature': 0.4,
            'max_tokens': 800,
          }),
        )
        .timeout(const Duration(seconds: 25));

    if (res.statusCode != 200) {
      throw Exception('Groq ${res.statusCode}: ${res.body}');
    }
    final data =
        jsonDecode(utf8.decode(res.bodyBytes)) as Map<String, dynamic>;
    final choices = data['choices'] as List<dynamic>;
    final message = choices.first['message'] as Map<String, dynamic>;
    return stripAskArucadMarkdown(_groqMessageText(message));
  }

  String _systemPrompt(
    List<CampusPlace> places,
    List<CampusEvent> events,
    List<CampusClub> clubs,
    List<CampusSport> sports,
    List<CampusService> services,
    List<CampusFoodVenue> foodVenues,
  ) {
    final placeLines = places.map((p) =>
        '- ${p.name} (${p.category}): ${p.density}, ${p.distance} uzaklıkta, ${p.street}');
    final eventLines = events.map((e) =>
        '- ${e.title} @ ${e.placeName}, saat ${e.time}, ${e.attendees} katılımcı');
    final clubLines = clubs
        .map((c) => '- ${c.name} (${c.category}): ${c.description}');
    final sportLines = sports.map((s) => '- ${s.name}: ${s.facility}');
    final serviceLines = services.map((s) {
      final where = [s.building, s.floor, s.room]
          .whereType<String>()
          .join(', ');
      return '- ${s.title} (${s.category}): ${s.description} '
          '${where.isNotEmpty ? 'Konum: $where. ' : ''}İletişim: ${s.contact}';
    });
    final shuttleLines = shuttleRoutes.map((r) {
      final next = nextDeparture(r.departures, DateTime.now());
      return '- ${r.name}: duraklar ${r.stops.take(3).join(', ')}…, sıradaki kalkış ${next.label} '
          '(${formatCountdown(next.until)})';
    });
    final tourLines = campusSites.map((s) =>
        '- ${s.name}: ${s.description} 360° tur: ${s.tourUrl}');
    final today = DateTime.now();
    final foodLines = foodVenues.map((v) {
      final todayMenu = v.menuForDay(today);
      final menuPart = todayMenu == null
          ? 'bugünün menüsü girilmemiş'
          : '${todayMenu.items.isEmpty ? 'menü detayı girilmemiş' : todayMenu.items.join(', ')}'
              '${todayMenu.price != null ? ' · ${todayMenu.price}' : ''}';
      return '- ${v.name}${v.hours != null ? ' (${v.hours})' : ''}: bugün → $menuPart';
    });

    return '''
Sen Ask ARUCAD'sın, ARUCAD (Girne/Kyrenia) kampüsünün yapay zekâ asistanısın.
Öğrencilere kampüs, akademik, idari, sosyal ve günlük ihtiyaç konularında kısa,
samimi ve doğru yanıt ver. Bilmediğin bir şeyi uydurma; emin değilsen bunu
söyle. Cevabın somut olabildiğince: bir yer, kişi, e-posta, saat ya da bağlantı
varsa mutlaka belirt — sadece genel konuşma, yönlendirici bilgi ver. Cevapların
Türkçe ve en fazla 3-4 cümle olsun. Markdown biçimlendirmesi KULLANMA: yıldız
(*), kare işareti (#), tire madde işareti (-), ters tırnak (`) gibi hiçbir
işaret kullanma — düz, temiz cümleler yaz.

Güncel kampüs verisi:

Yerler:
${placeLines.join('\n')}

Etkinlikler:
${eventLines.join('\n')}

Kulüpler:
${clubLines.join('\n')}

Spor imkânları:
${sportLines.join('\n')}

Kampüs Hizmetleri (Öğrenci İşleri, PDR, Kariyer, Kütüphane, IT, Yurt, vb.):
${serviceLines.join('\n')}

Servis (shuttle) hatları:
${shuttleLines.join('\n')}

Kampüsler ve 360° sanal turlar:
${tourLines.join('\n')}

Yemek noktaları:
${foodLines.join('\n')}
''';
  }
}
