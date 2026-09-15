import 'package:flutter/material.dart';

import '../../core/auth/app_settings_store.dart';
import '../../core/l10n/app_strings.dart';
import '../../core/legal/policy_consent.dart';
import '../../core/theme/arucad_theme.dart';
import '../legal/legal_document_screen.dart';

/// Which language to show the notice in.
///
/// Read from the *device* locale, not the in-app language picker: this
/// screen runs before anyone has signed in or chosen anything. Anything
/// other than Turkish, English or Russian falls back to English.
AppLanguage noticeLanguageFor(Locale locale) => switch (locale.languageCode) {
      'tr' => AppLanguage.tr,
      'ru' => AppLanguage.ru,
      'en' => AppLanguage.en,
      _ => AppLanguage.en,
    };

/// Gate: nobody reaches the app until they have accepted.
///
/// Two things have to be true, and they are different things:
///
///   * the person has been shown the notice and ticked the box — held on
///     the device, because this runs before there is an account to attach
///     it to;
///   * the account has a consent record on the server — which is what
///     survives a reinstall and what re-opens this gate when either
///     document changes.
///
/// This widget owns the first. [PolicyConsentClient] owns the second, and
/// the app calls it once a student is signed in.
class PrivacyNoticeGate extends StatefulWidget {
  const PrivacyNoticeGate({super.key, required this.child});

  final Widget child;

  @override
  State<PrivacyNoticeGate> createState() => _PrivacyNoticeGateState();
}

class _PrivacyNoticeGateState extends State<PrivacyNoticeGate> {
  bool? _accepted;

  @override
  void initState() {
    super.initState();
    AppSettingsStore.privacyNoticeAcknowledged().then((ack) {
      if (mounted) setState(() => _accepted = ack);
    });
    policyReacceptanceRequired.addListener(_onReacceptanceRequired);
  }

  @override
  void dispose() {
    policyReacceptanceRequired.removeListener(_onReacceptanceRequired);
    super.dispose();
  }

  /// The server has told us this account accepted an older version.
  void _onReacceptanceRequired() {
    if (!mounted) return;
    if (policyReacceptanceRequired.value) setState(() => _accepted = false);
  }

  Future<void> _accept() async {
    await AppSettingsStore.setPrivacyNoticeAcknowledged();
    policyReacceptanceRequired.value = false;
    if (mounted) setState(() => _accepted = true);
  }

  @override
  Widget build(BuildContext context) {
    if (_accepted == null) return const SizedBox.shrink();
    if (_accepted == true) return widget.child;

    return PrivacyNoticeScreen(
      onAccept: _accept,
      policyChanged: policyReacceptanceRequired.value,
    );
  }
}

class PrivacyNoticeScreen extends StatefulWidget {
  const PrivacyNoticeScreen({
    super.key,
    required this.onAccept,
    this.language,
    this.policyChanged = false,
  });

  final Future<void> Function() onAccept;

  /// Overridden in tests and when the gate reopens after the student has
  /// already chosen a language in the app.
  final AppLanguage? language;

  /// True when this is a re-acceptance of updated documents rather than a
  /// first run, which is a different thing to tell someone.
  final bool policyChanged;

  @override
  State<PrivacyNoticeScreen> createState() => _PrivacyNoticeScreenState();
}

class _PrivacyNoticeScreenState extends State<PrivacyNoticeScreen> {
  bool _ticked = false;
  bool _saving = false;

  AppLanguage get _language =>
      widget.language ??
      noticeLanguageFor(WidgetsBinding.instance.platformDispatcher.locale);

  Future<void> _accept() async {
    if (!_ticked || _saving) return;
    setState(() => _saving = true);
    await widget.onAccept();
    if (mounted) setState(() => _saving = false);
  }

  void _open(String assetPath, String title) {
    Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => LegalDocumentScreen(
          title: title,
          assetPath: assetPath,
          language: _language,
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings(_language);

    return Scaffold(
      backgroundColor: Colors.white,
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 440),
            child: Column(
              children: [
                Expanded(
                  child: ListView(
                    padding: const EdgeInsets.fromLTRB(24, 36, 24, 8),
                    children: [
                      Image.asset(
                        'assets/images/arucad_home_logo.png',
                        height: 44,
                        filterQuality: FilterQuality.high,
                      ),
                      const SizedBox(height: 40),
                      Text(
                        widget.policyChanged
                            ? strings.t('consent_updated_title')
                            : strings.t('consent_title'),
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                          fontSize: 30,
                          height: 1.15,
                          fontWeight: FontWeight.w900,
                          letterSpacing: -0.5,
                          color: ArucadColors.ink,
                        ),
                      ),
                      const SizedBox(height: 14),
                      Text(
                        widget.policyChanged
                            ? strings.t('consent_updated_body')
                            : strings.t('consent_subtitle'),
                        textAlign: TextAlign.center,
                        style: const TextStyle(
                          fontSize: 16,
                          height: 1.45,
                          color: ArucadColors.muted,
                        ),
                      ),
                      const SizedBox(height: 30),
                      _ModerationCard(strings: strings),
                      const SizedBox(height: 24),
                      _DocumentLinks(
                        privacyLabel: strings.t('consent_privacy_link'),
                        guidelinesLabel: strings.t('consent_guidelines_link'),
                        onPrivacy: () => _open(
                          'assets/legal/privacy.md',
                          strings.t('consent_privacy_link'),
                        ),
                        onGuidelines: () => _open(
                          'assets/legal/community-guidelines.md',
                          strings.t('consent_guidelines_link'),
                        ),
                      ),
                    ],
                  ),
                ),

                // Outside the scroll view on purpose: the control that
                // unblocks the app must not be something a student has to
                // discover by scrolling to the end of a legal text.
                Padding(
                  padding: const EdgeInsets.fromLTRB(24, 4, 24, 20),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      _ConsentCheckbox(
                        value: _ticked,
                        label: strings.t('consent_accept'),
                        onChanged: (value) =>
                            setState(() => _ticked = value ?? false),
                      ),
                      const SizedBox(height: 14),
                      SizedBox(
                        height: 56,
                        child: FilledButton(
                          // Disabled, not hidden: a control that is there
                          // but will not act says "something is missing",
                          // where one that vanishes says "this is broken".
                          onPressed: _ticked && !_saving ? _accept : null,
                          style: FilledButton.styleFrom(
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(14),
                            ),
                            disabledBackgroundColor: const Color(0xFFE9EAEC),
                            disabledForegroundColor: ArucadColors.muted,
                          ),
                          child: Text(
                            strings.t('consent_continue'),
                            style: const TextStyle(
                              fontSize: 16,
                              fontWeight: FontWeight.w700,
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

/// "How moderation works" — the three things a student most needs to know
/// before agreeing, said plainly.
///
/// Summarised here and stated in full in the two documents a tap away. The
/// wording comes from the bundled string table rather than being pulled out
/// of the policy file at runtime, so it cannot fail to load and leave a
/// checkbox floating above nothing.
class _ModerationCard extends StatelessWidget {
  const _ModerationCard({required this.strings});

  final AppStrings strings;

  static const _points = [
    'consent_moderation_automated',
    'consent_moderation_reviewers',
    'consent_moderation_referral',
  ];

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.fromLTRB(20, 20, 20, 6),
      decoration: BoxDecoration(
        color: const Color(0xFFF7F8FA),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: const Color(0xFFE9EBEF)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(
            strings.t('consent_moderation_heading'),
            style: const TextStyle(
              fontSize: 18,
              fontWeight: FontWeight.w800,
              color: ArucadColors.ink,
            ),
          ),
          const SizedBox(height: 16),
          for (final key in _points)
            Padding(
              padding: const EdgeInsets.only(bottom: 16),
              child: Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Icon(
                    Icons.verified_user_outlined,
                    size: 26,
                    color: Color(0xFF7C8595),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Text(
                      strings.t(key),
                      style: const TextStyle(
                        fontSize: 15,
                        height: 1.4,
                        color: Color(0xFF3F4754),
                      ),
                    ),
                  ),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

/// The two documents, named and reachable before anyone agrees to them.
class _DocumentLinks extends StatelessWidget {
  const _DocumentLinks({
    required this.privacyLabel,
    required this.guidelinesLabel,
    required this.onPrivacy,
    required this.onGuidelines,
  });

  final String privacyLabel;
  final String guidelinesLabel;
  final VoidCallback onPrivacy;
  final VoidCallback onGuidelines;

  @override
  Widget build(BuildContext context) {
    // Wraps rather than overflows: the Russian labels are close to twice
    // the width of the English ones and do not fit on one line on a narrow
    // phone.
    return Wrap(
      alignment: WrapAlignment.center,
      crossAxisAlignment: WrapCrossAlignment.center,
      spacing: 12,
      runSpacing: 2,
      children: [
        _DocumentLink(label: privacyLabel, onTap: onPrivacy),
        const Text('•', style: TextStyle(color: ArucadColors.muted)),
        _DocumentLink(label: guidelinesLabel, onTap: onGuidelines),
      ],
    );
  }
}

class _DocumentLink extends StatelessWidget {
  const _DocumentLink({required this.label, required this.onTap});

  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(6),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 2),
        child: Text(
          label,
          style: const TextStyle(
            fontSize: 15.5,
            fontWeight: FontWeight.w600,
            color: ArucadColors.ink,
            decoration: TextDecoration.underline,
          ),
        ),
      ),
    );
  }
}

/// The checkbox, with its whole label as the tap target.
///
/// A 20px box is a small thing to hit on a phone, and this is the one
/// control standing between a student and the app.
class _ConsentCheckbox extends StatelessWidget {
  const _ConsentCheckbox({
    required this.value,
    required this.label,
    required this.onChanged,
  });

  final bool value;
  final String label;
  final ValueChanged<bool?> onChanged;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: () => onChanged(!value),
      borderRadius: BorderRadius.circular(10),
      child: Padding(
        padding: const EdgeInsets.symmetric(vertical: 6),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              width: 26,
              height: 26,
              child: Checkbox(
                value: value,
                onChanged: onChanged,
                materialTapTargetSize: MaterialTapTargetSize.shrinkWrap,
                visualDensity: VisualDensity.compact,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(6),
                ),
                side: const BorderSide(color: Color(0xFF9AA2B1), width: 1.6),
              ),
            ),
            const SizedBox(width: 14),
            Expanded(
              child: Text(
                label,
                style: const TextStyle(
                  fontSize: 15.5,
                  height: 1.4,
                  color: ArucadColors.ink,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
