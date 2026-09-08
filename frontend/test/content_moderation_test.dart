import 'package:arucad_campus_prototype/core/services/content_moderation.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('fast moderation blocks Turkish, English and Russian high-risk text',
      () {
    expect(moderateText('Seni vuracağım').allowed, isFalse);
    expect(moderateText('I will kill you').allowed, isFalse);
    expect(moderateText('Я тебя убью').allowed, isFalse);
  });

  test('fast moderation catches punctuation and leetspeak obfuscation', () {
    expect(moderateText('f.u.c.k').allowed, isFalse);
    expect(moderateText('s@l4k').allowed, isFalse);
  });

  test('fast moderation blocks hate speech and sexual harassment phrases', () {
    final hate = moderateText(
        'Bu göçmenler insan değil, hepsini ülkeden sürmek lazım');
    expect(hate.allowed, isFalse);
    expect(hate.reason, contains('nefret'));

    final sex = moderateText(
        "Give me your number, gorgeous. I won't let you sleep tonight.");
    expect(sex.allowed, isFalse);
    expect(sex.reason, contains('cinsel'));
  });

  test('mild campus exclamations stay allowed', () {
    expect(
        moderateText('Bugün stüdyoda harika bir gün geçirdim').allowed, isTrue);
    expect(moderateText('Bu uygulama yine çöktü, lanet olsun.').allowed, isTrue);
  });

  test(
      'political party campaigning is blocked but student council elections are allowed',
      () {
    final political = moderateText(
        'CHP ve AKP arasındaki tartışma bu sabah yine gündemdeydi.');
    expect(political.allowed, isFalse);
    expect(political.reason, contains('siyasi'));

    expect(
        moderateText('Kulüp başkanlığı seçimi için adaylık başvuruları başladı.')
            .allowed,
        isTrue);
  });

  test('stretched-letter evasion is still caught', () {
    expect(moderateText('saaaalak davranma').allowed, isFalse);
  });
}
