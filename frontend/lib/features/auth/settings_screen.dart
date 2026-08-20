import 'package:flutter/material.dart';
import 'package:local_auth/local_auth.dart';

import 'package:arucad_campus_prototype/core/auth/app_settings_store.dart';
import 'package:arucad_campus_prototype/core/auth/biometric_auth_provider.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';

class SettingsScreen extends StatefulWidget {
  final AuthProvider authProvider;
  final String language;
  final ValueChanged<String> onLanguageChanged;

  const SettingsScreen({
    super.key,
    required this.authProvider,
    required this.language,
    required this.onLanguageChanged,
  });

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> {
  final _biometric = BiometricAuthProvider();
  late String _language = widget.language;
  List<BiometricType> _availableBiometrics = [];
  bool _checkingBiometrics = true;
  bool _biometricEnabled = false;
  BiometricMethod? _enrolledMethod;
  String? _enrolledEmail;

  @override
  void initState() {
    super.initState();
    _loadBiometricState();
  }

  Future<void> _loadBiometricState() async {
    final available = await _biometric.availableBiometrics();
    final enabled = await AppSettingsStore.biometricEnabled();
    final method = await AppSettingsStore.biometricMethod();
    final email = await AppSettingsStore.biometricEmail();
    if (!mounted) return;
    setState(() {
      _availableBiometrics = available;
      _biometricEnabled = enabled;
      _enrolledMethod = method;
      _enrolledEmail = email;
      _checkingBiometrics = false;
    });
  }

  Future<void> _setLanguage(String lang) async {
    setState(() => _language = lang);
    await AppSettingsStore.setLanguage(lang);
    widget.onLanguageChanged(lang);
  }

  void _forgotPassword() {
    showDialog<void>(
      context: context,
      builder: (_) => AlertDialog(
        title: const Text('Şifremi Unuttum'),
        content: const Text(
            'Şifre sıfırlama ARUCAD Bilgi İşlem tarafından yönetilir. Lütfen kurumsal e-postan üzerinden destek@arucad.edu.tr adresine başvur.'),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context),
              child: const Text('Tamam')),
        ],
      ),
    );
  }

  Future<void> _enroll(BiometricMethod method) async {
    final credentials = await _askCredentials();
    if (credentials == null) return;
    final valid = await widget.authProvider
        .signInWithCredentials(credentials.$1, credentials.$2);
    if (!mounted) return;
    if (!valid) {
      ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('E-posta veya şifre hatalı.')));
      return;
    }
    final confirmed = await _biometric.unlockWithBiometrics(
        reason: method == BiometricMethod.face
            ? 'Face ID kaydını onayla'
            : 'Parmak izi kaydını onayla');
    if (!mounted) return;
    if (!confirmed) {
      ScaffoldMessenger.of(context).showSnackBar(const SnackBar(
          content: Text('Biyometrik doğrulama tamamlanamadı.')));
      return;
    }
    final email = credentials.$1.trim().toLowerCase();
    await AppSettingsStore.enableBiometric(method: method, email: email);
    if (!mounted) return;
    setState(() {
      _biometricEnabled = true;
      _enrolledMethod = method;
      _enrolledEmail = email;
    });
    ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Biyometrik giriş etkinleştirildi.')));
  }

  Future<(String, String)?> _askCredentials() {
    final emailController = TextEditingController();
    final passwordController = TextEditingController();
    return showDialog<(String, String)?>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Kimliğini doğrula'),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(
              controller: emailController,
              decoration: const InputDecoration(
                  labelText: 'E-posta veya Öğrenci No')),
          const SizedBox(height: 10),
          TextField(
              controller: passwordController,
              obscureText: true,
              decoration: const InputDecoration(labelText: 'Şifre')),
        ]),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('Vazgeç')),
          FilledButton(
              onPressed: () => Navigator.pop(
                  ctx, (emailController.text, passwordController.text)),
              child: const Text('Devam')),
        ],
      ),
    );
  }

  Future<void> _disableBiometric() async {
    await AppSettingsStore.disableBiometric();
    if (!mounted) return;
    setState(() {
      _biometricEnabled = false;
      _enrolledMethod = null;
      _enrolledEmail = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    final hasFingerprint =
        _availableBiometrics.contains(BiometricType.fingerprint) ||
            _availableBiometrics.contains(BiometricType.strong) ||
            _availableBiometrics.contains(BiometricType.weak);
    final hasFace = _availableBiometrics.contains(BiometricType.face);

    return Scaffold(
      appBar: AppBar(title: const Text('Ayarlar')),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 760),
          child: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          const Text('Dil',
              style: TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
          const SizedBox(height: 10),
          Card(
            child: Column(children: [
              for (final lang in const ['TR', 'EN', 'RU'])
                ListTile(
                  onTap: () => _setLanguage(lang),
                  title: Text(_languageLabel(lang)),
                  trailing: lang == _language
                      ? const Icon(Icons.check_circle,
                          color: ArucadColors.primary)
                      : const Icon(Icons.circle_outlined,
                          color: ArucadColors.muted),
                ),
            ]),
          ),
          const SizedBox(height: 22),
          const Text('Hesap',
              style: TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
          const SizedBox(height: 10),
          Card(
            child: ListTile(
              leading: const Icon(Icons.lock_reset_outlined),
              title: const Text('Şifremi Unuttum'),
              trailing: const Icon(Icons.chevron_right),
              onTap: _forgotPassword,
            ),
          ),
          const SizedBox(height: 22),
          const Text('Biyometrik Kimlik Doğrulama',
              style: TextStyle(fontWeight: FontWeight.w900, fontSize: 16)),
          const SizedBox(height: 10),
          if (_checkingBiometrics)
            const Center(
                child: Padding(
                    padding: EdgeInsets.all(20),
                    child: CircularProgressIndicator()))
          else if (_availableBiometrics.isEmpty)
            const Card(
              color: ArucadColors.mist,
              child: Padding(
                padding: EdgeInsets.all(16),
                child: Text(
                    'Bu cihazda/tarayıcıda biyometrik doğrulama kullanılamıyor. Parmak izi ve Face ID yalnızca desteklenen bir mobil cihazda çalışır.',
                    style: TextStyle(color: ArucadColors.muted)),
              ),
            )
          else if (_biometricEnabled)
            Card(
              child: ListTile(
                leading: Icon(
                    _enrolledMethod == BiometricMethod.face
                        ? Icons.face_outlined
                        : Icons.fingerprint,
                    color: ArucadColors.primary),
                title: Text(_enrolledMethod == BiometricMethod.face
                    ? 'Face ID etkin'
                    : 'Parmak izi etkin'),
                subtitle: Text(_enrolledEmail ?? ''),
                trailing: TextButton(
                    onPressed: _disableBiometric,
                    child: const Text('Kaldır')),
              ),
            )
          else
            Column(children: [
              if (hasFingerprint)
                SizedBox(
                  width: double.infinity,
                  child: OutlinedButton.icon(
                    onPressed: () => _enroll(BiometricMethod.fingerprint),
                    icon: const Icon(Icons.fingerprint),
                    label: const Text('Parmak İzi ile Kaydol'),
                  ),
                ),
              if (hasFingerprint && hasFace) const SizedBox(height: 10),
              if (hasFace)
                SizedBox(
                  width: double.infinity,
                  child: OutlinedButton.icon(
                    onPressed: () => _enroll(BiometricMethod.face),
                    icon: const Icon(Icons.face_outlined),
                    label: const Text('Face ID ile Kaydol'),
                  ),
                ),
            ]),
        ],
      ),
          ),
        ),
    );
  }

  String _languageLabel(String code) => switch (code) {
        'EN' => 'English',
        'RU' => 'Русский',
        _ => 'Türkçe',
      };
}
