import 'package:flutter/foundation.dart';
import 'package:flutter/services.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/features/legal/legal_document_screen.dart';

/// The documents behind the consent checkbox have to reach the reader in
/// their own language.
///
/// The privacy policy was once a single Turkish asset loaded regardless of
/// the chosen language, so a student reading the app in English or Russian
/// was shown a document they could not read — including the part telling
/// them their posts are reviewed and may be referred for disciplinary
/// proceedings. A notice nobody can read has not been given.
///
/// The checkbox now names two documents, so both are covered here.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  const privacy = 'assets/legal/privacy.md';
  const guidelines = 'assets/legal/community-guidelines.md';

  Future<String> load(String asset, AppLanguage language) =>
      LegalDocumentScreen.loadFor(asset, language);

  // ---- the right file in the right language ---------------------------

  test('the privacy policy loads in each language', () async {
    expect(await load(privacy, AppLanguage.tr), contains('Gizlilik Politikası'));
    expect(await load(privacy, AppLanguage.en), contains('Privacy Policy'));
    expect(await load(privacy, AppLanguage.ru),
        contains('Политика конфиденциальности'));
  });

  test('the community guidelines load in each language', () async {
    expect(await load(guidelines, AppLanguage.tr), contains('Topluluk Kuralları'));
    expect(
        await load(guidelines, AppLanguage.en), contains('Community Guidelines'));
    expect(await load(guidelines, AppLanguage.ru), contains('Правила сообщества'));
  });

  // ---- the substance, not just the heading ----------------------------

  /// Three things the moderation section has to say in every language:
  /// content may be checked automatically, serious cases may go to the
  /// university, and a person may be involved in the decision. A heading
  /// alone is not the notice.
  test('every privacy translation carries the same moderation terms',
      () async {
    const required = {
      AppLanguage.tr: [
        'otomatik güvenlik sistemleri',
        'üniversite birimlerine',
        'insan incelemesi',
      ],
      AppLanguage.en: [
        'automated safety systems',
        'university authorities',
        'human review',
      ],
      AppLanguage.ru: [
        'автоматизированными системами безопасности',
        'органам университета',
        'участием человека',
      ],
    };

    for (final entry in required.entries) {
      final text = await load(privacy, entry.key);
      for (final phrase in entry.value) {
        expect(text, contains(phrase),
            reason: 'The ${entry.key.name} privacy policy is missing: $phrase');
      }
    }
  });

  /// The guidelines are what enforcement is actually measured against, so
  /// the list of things that can happen to an account has to be in every
  /// language, not only the one the author wrote first.
  test('every guidelines translation lists the enforcement actions',
      () async {
    const required = {
      AppLanguage.tr: ['Uyarı vermek', 'kalıcı olarak kapatmak', 'İtiraz'],
      AppLanguage.en: ['Issue a warning', 'permanently disable', 'Appeals'],
      AppLanguage.ru: ['предупреждение', 'навсегда закрыть', 'Обжалование'],
    };

    for (final entry in required.entries) {
      final text = await load(guidelines, entry.key);
      for (final phrase in entry.value) {
        expect(text, contains(phrase),
            reason: 'The ${entry.key.name} guidelines are missing: $phrase');
      }
    }
  });

  // ---- fallback and staleness ------------------------------------------

  /// A document with no translation must show the governing Turkish text
  /// rather than an empty screen.
  test('an untranslated document falls back to Turkish', () async {
    final text = await load('assets/legal/safety.md', AppLanguage.ru);

    expect(text, isNotEmpty);
    expect(() => rootBundle.loadString('assets/legal/safety.ru.md'),
        throwsA(isA<FlutterError>()),
        reason: 'Precondition: safety.md is the untranslated case here.');
  });

  /// Video was removed on 14 September 2026, so no document may describe
  /// moderating it.
  test('no translation still describes video moderation', () async {
    for (final asset in [privacy, guidelines]) {
      for (final language in AppLanguage.values) {
        final text = await load(asset, language);

        expect(text, isNot(contains('video moderasyonu ARUCAD')));
        expect(text, isNot(contains('Text, image and video moderation')));
      }
    }
  });

  /// These are drafts until legal review signs them off, and the bracketed
  /// fields are the ones the institution still has to fill in. Both facts
  /// should be visible to whoever opens the file, in every language.
  test('every document is still marked as awaiting legal review', () async {
    const marker = {
      AppLanguage.tr: 'Taslak',
      AppLanguage.en: 'Draft',
      AppLanguage.ru: 'Черновик',
    };

    for (final asset in [privacy, guidelines]) {
      for (final entry in marker.entries) {
        expect(await load(asset, entry.key), contains(entry.value),
            reason: '$asset in ${entry.key.name} lost its draft marker.');
      }
    }
  });
}
