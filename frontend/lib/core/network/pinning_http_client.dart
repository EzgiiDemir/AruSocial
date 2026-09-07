import 'dart:io';

import 'package:crypto/crypto.dart';
import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;

/// SHA-256 (hex) of the API leaf certificate. Empty → no pinning (LAN HTTP).
Future<void> assertCertificatePins(Uri apiOrigin, List<String> pins) async {
  if (kIsWeb || pins.isEmpty || apiOrigin.scheme != 'https') return;
  final expected = pins
      .map((p) => p.replaceAll(':', '').replaceAll(' ', '').toLowerCase())
      .where((p) => p.isNotEmpty)
      .toList();
  if (expected.isEmpty) return;

  final origin = Uri(
    scheme: apiOrigin.scheme,
    host: apiOrigin.host,
    port: apiOrigin.hasPort ? apiOrigin.port : null,
    path: '/',
  );
  final client = HttpClient();
  try {
    final request = await client.getUrl(origin);
    final response = await request.close();
    final cert = response.certificate;
    await response.drain<void>();
    if (cert == null) {
      throw StateError('TLS pin: sunucu sertifikası okunamadı.');
    }
    final hash = sha256.convert(cert.der).toString();
    if (!expected.contains(hash)) {
      throw StateError(
        'TLS pin uyuşmadı (SHA-256 $hash). Beklenen değer --dart-define=TLS_PIN_SHA256 ile verilmeli.',
      );
    }
  } finally {
    client.close(force: true);
  }
}

http.Client createApiHttpClient() => http.Client();
