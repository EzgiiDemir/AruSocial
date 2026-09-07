import 'package:flutter/material.dart';

import 'package:arucad_campus_prototype/app/config/app_config.dart';
import 'package:arucad_campus_prototype/core/auth/app_settings_store.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

/// First-run screen for release APKs built without `--dart-define=API_BASE_URL`.
/// Saves a LAN / public API host, then calls [onSaved] to boot the real app.
class ServerSetupApp extends StatefulWidget {
  final Future<void> Function() onSaved;

  const ServerSetupApp({super.key, required this.onSaved});

  @override
  State<ServerSetupApp> createState() => _ServerSetupAppState();
}

class _ServerSetupAppState extends State<ServerSetupApp> {
  final _hostController = TextEditingController();
  final _portController = TextEditingController(text: '4000');
  bool _saving = false;
  String? _error;

  @override
  void dispose() {
    _hostController.dispose();
    _portController.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final host = _hostController.text.trim();
    final port = int.tryParse(_portController.text.trim()) ?? 4000;
    if (host.isEmpty) {
      setState(() => _error = 'Bilgisayarın IP adresini gir (ör. 192.168.1.8).');
      return;
    }
    final url = AppConfig.buildLocalApiBaseUrl(host, port: port);
    if (url.isEmpty || AppConfig.isLoopbackApiUrl(url)) {
      setState(() => _error =
          'localhost / 127.0.0.1 kullanılamaz. Telefon aynı Wi-Fi\'da bilgisayarın LAN IP\'sine bağlanmalı.');
      return;
    }
    setState(() {
      _saving = true;
      _error = null;
    });
    await AppSettingsStore.setRuntimeApi(useRestApi: true, host: host, port: port);
    if (!mounted) return;
    await widget.onSaved();
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      theme: ArucadTheme.data(),
      home: Scaffold(
        body: SafeArea(
          child: Center(
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 420),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const Icon(Icons.dns_outlined, size: 48, color: ArucadColors.primary),
                    const SizedBox(height: 16),
                    const Text(
                      'API sunucusu',
                      textAlign: TextAlign.center,
                      style: TextStyle(fontSize: 22, fontWeight: FontWeight.w900),
                    ),
                    const SizedBox(height: 10),
                    const Text(
                      'Release APK bilgisayarındaki Laravel API\'ye bağlanır. '
                      'Backend\'i şu komutla başlat:\n'
                      'php artisan serve --host=0.0.0.0 --port=4000\n\n'
                      'Telefon ve bilgisayar aynı Wi-Fi\'da olmalı.',
                      style: TextStyle(color: ArucadColors.muted, height: 1.45),
                    ),
                    const SizedBox(height: 20),
                    TextField(
                      controller: _hostController,
                      decoration: const InputDecoration(
                        labelText: 'Bilgisayar IP (LAN)',
                        hintText: '192.168.1.8',
                        border: OutlineInputBorder(),
                      ),
                      keyboardType: TextInputType.url,
                      textInputAction: TextInputAction.next,
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: _portController,
                      decoration: const InputDecoration(
                        labelText: 'Port',
                        border: OutlineInputBorder(),
                      ),
                      keyboardType: TextInputType.number,
                    ),
                    if (_error != null) ...[
                      const SizedBox(height: 12),
                      Text(_error!,
                          style: const TextStyle(color: ArucadColors.danger, fontSize: 13)),
                    ],
                    const SizedBox(height: 20),
                    FilledButton(
                      onPressed: _saving ? null : _save,
                      child: _saving
                          ? const SizedBox(
                              width: 20,
                              height: 20,
                              child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                            )
                          : const Text('Kaydet ve devam et'),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

bool isReleaseConfigReady(AppConfig config) {
  if (!config.useRestApi) return false;
  if (config.apiBaseUrl.trim().isEmpty) return false;
  return !AppConfig.isLoopbackApiUrl(config.apiBaseUrl);
}
