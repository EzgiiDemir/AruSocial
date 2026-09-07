import 'package:flutter/material.dart';

import '../../core/auth/app_settings_store.dart';
import '../../core/theme/arucad_theme.dart';

/// Shown once, before sign-in — detected from the *device* locale (not the
/// in-app language picker, which nobody has touched yet at this point).
/// Anything other than Turkish/English/Russian falls back to English, same
/// as an "international" default.
enum _NoticeLanguage { tr, en, ru }

_NoticeLanguage _noticeLanguageFor(Locale locale) => switch (locale.languageCode) {
      'tr' => _NoticeLanguage.tr,
      'ru' => _NoticeLanguage.ru,
      'en' => _NoticeLanguage.en,
      _ => _NoticeLanguage.en,
    };

class _NoticeCopy {
  final String title;
  final String body;
  final String action;
  const _NoticeCopy({required this.title, required this.body, required this.action});
}

const _copy = <_NoticeLanguage, _NoticeCopy>{
  _NoticeLanguage.tr: _NoticeCopy(
    title: 'Gizliliğiniz Önceliğimizdir',
    body: 'Bu uygulama, öğrencilerin güvenli ve özgür bir sosyal ortamda '
        'iletişim kurabilmeleri için tasarlanmıştır.\n\n'
        'Kişisel verileriniz izniniz dışında kullanılmaz veya üçüncü '
        'kişilerle paylaşılmaz. Gizliliğinize saygı duyuyor, güvenli bir '
        'kullanım deneyimi sunmayı önemsiyoruz.',
    action: 'Okudum, bir daha gösterme',
  ),
  _NoticeLanguage.ru: _NoticeCopy(
    title: 'Ваша конфиденциальность — наш приоритет',
    body: 'Это приложение создано, чтобы студенты могли общаться в '
        'безопасной и свободной социальной среде.\n\n'
        'Ваши личные данные не используются без вашего согласия и не '
        'передаются третьим лицам. Мы уважаем вашу конфиденциальность и '
        'заботимся о безопасности использования приложения.',
    action: 'Прочитал(а), больше не показывать',
  ),
  _NoticeLanguage.en: _NoticeCopy(
    title: 'Your Privacy Is Our Priority',
    body: 'This app is designed so students can communicate in a safe and '
        'open social environment.\n\n'
        'Your personal data is never used or shared with third parties '
        'without your consent. We respect your privacy and care about '
        'giving you a safe experience.',
    action: "I've read it, don't show again",
  ),
};

/// Gate widget: shows the notice once (device-wide, before any sign-in),
/// then always renders [child] on every later launch.
class PrivacyNoticeGate extends StatefulWidget {
  final Widget child;
  const PrivacyNoticeGate({super.key, required this.child});

  @override
  State<PrivacyNoticeGate> createState() => _PrivacyNoticeGateState();
}

class _PrivacyNoticeGateState extends State<PrivacyNoticeGate> {
  bool? _acknowledged;

  @override
  void initState() {
    super.initState();
    AppSettingsStore.privacyNoticeAcknowledged().then((ack) {
      if (mounted) setState(() => _acknowledged = ack);
    });
  }

  Future<void> _acknowledge() async {
    await AppSettingsStore.setPrivacyNoticeAcknowledged();
    if (mounted) setState(() => _acknowledged = true);
  }

  @override
  Widget build(BuildContext context) {
    if (_acknowledged == null) return const SizedBox.shrink();
    if (_acknowledged == true) return widget.child;
    return PrivacyNoticeScreen(onAcknowledge: _acknowledge);
  }
}

class PrivacyNoticeScreen extends StatelessWidget {
  final VoidCallback onAcknowledge;
  const PrivacyNoticeScreen({super.key, required this.onAcknowledge});

  @override
  Widget build(BuildContext context) {
    final language =
        _noticeLanguageFor(WidgetsBinding.instance.platformDispatcher.locale);
    final copy = _copy[language]!;
    return Scaffold(
      backgroundColor: Colors.white,
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 440),
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: 28),
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Image.asset(
                    'assets/images/arucad_home_logo.png',
                    height: 56,
                    filterQuality: FilterQuality.high,
                  ),
                  const SizedBox(height: 40),
                  Text(
                    copy.title,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                        fontSize: 22,
                        fontWeight: FontWeight.w900,
                        color: ArucadColors.ink),
                  ),
                  const SizedBox(height: 16),
                  Text(
                    copy.body,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                        fontSize: 14.5, height: 1.55, color: ArucadColors.muted),
                  ),
                  const SizedBox(height: 32),
                  SizedBox(
                    width: double.infinity,
                    child: FilledButton(
                      onPressed: onAcknowledge,
                      style: FilledButton.styleFrom(
                          padding: const EdgeInsets.symmetric(vertical: 16)),
                      child: Text(copy.action,
                          style: const TextStyle(fontWeight: FontWeight.w800)),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
