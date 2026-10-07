import 'package:flutter/material.dart';
import 'package:local_auth/local_auth.dart';

import 'package:arucad_campus_prototype/core/auth/app_settings_store.dart';
import 'package:arucad_campus_prototype/core/auth/biometric_auth_provider.dart';
import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/services/contracts.dart';
import 'package:arucad_campus_prototype/core/theme/arucad_theme.dart';
import 'package:arucad_campus_prototype/features/widgets/campus_back_button.dart';

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

  @override
  void didUpdateWidget(covariant SettingsScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.language != widget.language) {
      _language = widget.language;
    }
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
    final s = AppLocale.of(context);
    showDialog<void>(
      context: context,
      builder: (_) => AlertDialog(
        title: Text(s.t('settings_forgot_password')),
        content: Text(s.t('settings_forgot_body')),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(context),
              child: Text(s.t('common_ok'))),
        ],
      ),
    );
  }

  Future<void> _enroll(BiometricMethod method) async {
    final s = AppLocale.of(context);
    final credentials = await _askCredentials();
    if (credentials == null) return;
    final valid = await widget.authProvider
        .signInWithCredentials(credentials.$1, credentials.$2);
    if (!mounted) return;
    if (!valid) {
      ScaffoldMessenger.of(context)
          .showSnackBar(SnackBar(content: Text(s.t('settings_bad_credentials'))));
      return;
    }
    final confirmed = await _biometric.unlockWithBiometrics(
        reason: method == BiometricMethod.face
            ? s.t('settings_confirm_face')
            : s.t('settings_confirm_finger'));
    if (!mounted) return;
    if (!confirmed) {
      ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(s.t('settings_biometric_failed'))));
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
        SnackBar(content: Text(s.t('settings_biometric_enabled'))));
  }

  Future<(String, String)?> _askCredentials() {
    final s = AppLocale.of(context);
    final emailController = TextEditingController();
    final passwordController = TextEditingController();
    return showDialog<(String, String)?>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(s.t('settings_verify_title')),
        content: Column(mainAxisSize: MainAxisSize.min, children: [
          TextField(
              controller: emailController,
              decoration: InputDecoration(
                  labelText: s.t('login_identifier'))),
          const SizedBox(height: 10),
          TextField(
              controller: passwordController,
              obscureText: true,
              decoration: InputDecoration(labelText: s.t('login_password'))),
        ]),
        actions: [
          TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: Text(s.t('common_cancel'))),
          FilledButton(
              onPressed: () => Navigator.pop(
                  ctx, (emailController.text, passwordController.text)),
              child: Text(s.t('common_continue'))),
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
    final s = AppLocale.of(context);
    final hasFingerprint =
        _availableBiometrics.contains(BiometricType.fingerprint) ||
            _availableBiometrics.contains(BiometricType.strong) ||
            _availableBiometrics.contains(BiometricType.weak);
    final hasFace = _availableBiometrics.contains(BiometricType.face);

    return Scaffold(
      appBar: AppBar(
        leading: const CampusBackButton(),
        title: Text(s.t('settings_title')),
      ),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 760),
          child: ListView(
            padding: const EdgeInsets.all(20),
            children: [
              Text(s.t('profile_language'),
                  style: const TextStyle(
                      fontWeight: FontWeight.w900, fontSize: 16)),
              const SizedBox(height: 10),
              Card(
                child: Column(children: [
                  for (final lang in const ['TR', 'EN', 'RU'])
                    ListTile(
                      onTap: () => _setLanguage(lang),
                      title: Text(_languageLabel(s, lang)),
                      trailing: lang == _language
                          ? const Icon(Icons.check_circle,
                              color: ArucadColors.primary)
                          : const Icon(Icons.circle_outlined,
                              color: ArucadColors.muted),
                    ),
                ]),
              ),
              const SizedBox(height: 22),
              Text(s.t('settings_account'),
                  style: const TextStyle(
                      fontWeight: FontWeight.w900, fontSize: 16)),
              const SizedBox(height: 10),
              Card(
                child: ListTile(
                  leading: const Icon(Icons.lock_reset_outlined),
                  title: Text(s.t('settings_forgot_password')),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: _forgotPassword,
                ),
              ),
              const SizedBox(height: 22),
              Text(s.t('settings_biometric'),
                  style: const TextStyle(
                      fontWeight: FontWeight.w900, fontSize: 16)),
              const SizedBox(height: 10),
              if (_checkingBiometrics)
                const Center(
                    child: Padding(
                        padding: EdgeInsets.all(20),
                        child: CircularProgressIndicator()))
              else if (_availableBiometrics.isEmpty)
                Card(
                  color: ArucadColors.mist,
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Text(s.t('settings_biometric_unavailable'),
                        style: const TextStyle(color: ArucadColors.muted)),
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
                        ? s.t('settings_biometric_face_on')
                        : s.t('settings_biometric_finger_on')),
                    subtitle: Text(_enrolledEmail ?? ''),
                    trailing: TextButton(
                        onPressed: _disableBiometric,
                        child: Text(s.t('common_remove'))),
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
                        label: Text(s.t('settings_enroll_finger')),
                      ),
                    ),
                  if (hasFingerprint && hasFace) const SizedBox(height: 10),
                  if (hasFace)
                    SizedBox(
                      width: double.infinity,
                      child: OutlinedButton.icon(
                        onPressed: () => _enroll(BiometricMethod.face),
                        icon: const Icon(Icons.face_outlined),
                        label: Text(s.t('settings_enroll_face')),
                      ),
                    ),
                ]),
            ],
          ),
        ),
      ),
    );
  }

  String _languageLabel(AppStrings s, String code) => switch (code) {
        'EN' => s.t('lang_en'),
        'RU' => s.t('lang_ru'),
        _ => s.t('lang_tr'),
      };
}
