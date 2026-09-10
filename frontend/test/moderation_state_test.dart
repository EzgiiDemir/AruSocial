import 'package:flutter_test/flutter_test.dart';

import 'package:arucad_campus_prototype/core/l10n/app_strings.dart';
import 'package:arucad_campus_prototype/core/models/moderation_state.dart';

void main() {
  group('ModerationState.fromApi', () {
    test('maps every status the backend can send', () {
      expect(ModerationState.fromApi('approved'), ModerationState.published);
      expect(ModerationState.fromApi('pending'), ModerationState.pending);
      expect(ModerationState.fromApi('review'), ModerationState.review);
      expect(ModerationState.fromApi('blocked'), ModerationState.blocked);
      expect(ModerationState.fromApi('moderation_error'),
          ModerationState.moderationError);
      expect(ModerationState.fromApi('removed'), ModerationState.removed);
    });

    /// The important one. A status this build has never seen — added on
    /// the server, or a typo, or a truncated payload — must not be read
    /// as publishable. Defaulting the other way would let the client show
    /// content the backend never approved.
    test('an unknown or missing status is never treated as published', () {
      for (final unknown in <String?>[
        null,
        '',
        'quarantined',
        'APPROVED',
        'some_future_state',
      ]) {
        final state = ModerationState.fromApi(unknown);
        expect(state, ModerationState.pending,
            reason: 'unrecognised "$unknown" must fall back to pending');
        expect(state.isVisibleToOthers, isFalse);
      }
    });

    test('only published is visible to other people', () {
      for (final state in ModerationState.values) {
        expect(state.isVisibleToOthers, state == ModerationState.published);
      }
    });

    test('the owner sees their own held content, but never blocked content',
        () {
      expect(ModerationState.pending.ownerCanSee, isTrue);
      expect(ModerationState.review.ownerCanSee, isTrue);
      expect(ModerationState.moderationError.ownerCanSee, isTrue);
      expect(ModerationState.blocked.ownerCanSee, isFalse);
    });
  });

  group('user-facing copy', () {
    /// Every state must have real text in all three languages. A missing
    /// key surfaces as the key itself, which is how "mod_explain_blocked"
    /// ends up on a student's screen.
    test('is translated in Turkish, English and Russian', () {
      for (final language in AppLanguage.values) {
        final strings = AppStrings(language);
        for (final state in ModerationState.values) {
          for (final text in [state.label(strings), state.explanation(strings)]) {
            expect(text.trim(), isNotEmpty);
            expect(text, isNot(startsWith('mod_')),
                reason: 'untranslated key leaked for $state in $language');
          }
        }
      }
    });

    /// Reasons must be things a person can act on. A model score is not
    /// an explanation, and publishing one invites arguments about the
    /// number instead of the behaviour.
    test('never exposes a model score or internal category', () {
      for (final language in AppLanguage.values) {
        final strings = AppStrings(language);
        for (final state in ModerationState.values) {
          final text = state.explanation(strings).toLowerCase();
          for (final leak in ['nsfw', 'score', 'model', '0.', 'threshold']) {
            expect(text.contains(leak), isFalse,
                reason: '$state leaks "$leak" in $language');
          }
        }
      }
    });
  });
}
