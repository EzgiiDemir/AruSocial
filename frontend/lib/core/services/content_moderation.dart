/// Fast on-device guard for content entry surfaces. The Laravel policy is the
/// authority in REST mode (and records strikes / bans); this mirror prevents
/// an abusive post from briefly appearing in mock/offline mode and keeps all
/// Turkish, English and Russian entry points consistent.
///
/// Category codes match the server policy (SEX, CSA, VIO, …). Mild S1 general
/// exclamations are intentionally not listed so ordinary campus posts stay
/// allowed; heavy PROF / HAR / THR terms remain blocked.
class ContentModerationException implements Exception {
  final String reason;
  const ContentModerationException(this.reason);
  @override
  String toString() => reason;
}

/// Turkish short labels shown in block messages (mirrors ModerationService).
const _categoryLabels = <String, String>{
  'SEX': 'cinsel içerik',
  'CSA': 'çocuk güvenliği',
  'VIO': 'şiddet',
  'THR': 'tehdit',
  'SELF': 'kendine zarar',
  'HATE': 'nefret söylemi',
  'HAR': 'taciz veya hakaret',
  'PROF': 'küfür veya saldırgan dil',
  'EXT': 'aşırıcılık',
  'CRIME': 'yasadışı faaliyet',
  'DRUG': 'uyuşturucu',
  'SCAM': 'dolandırıcılık',
  'CYBER': 'siber kötüye kullanım',
  'PRIV': 'mahremiyet ihlali',
  'IMP': 'kimliğe bürünme',
  'SPAM': 'spam',
  'MISINFO': 'zararlı yanlış bilgi',
  'IP': 'telif ihlali',
  'ANIMAL': 'hayvan istismarı',
  'MINOR': 'çocuk güvenliği',
  'POL': 'siyasi içerik',
};

const _blockedTerms = <String, List<String>>{
  'PROF': [
    'orospu',
    'piç',
    'yavşak',
    'salak',
    'aptal',
    'ahmak',
    'fuck',
    'bitch',
    'asshole',
    'fck',
    'siktir',
    'amına koy',
    'amina koy',
    'şerefsiz',
    'serefsiz',
    'gerizekalı',
    'gerizekali',
    'mal herif',
    'сука',
    'бляд',
    'идиот',
    'тупой',
    'хуй',
    'мразь',
    'мудак',
    'дебил',
    'придурок',
    'пошёл на',
    'пошел на',
  ],
  'HAR': [
    'defol git',
    'seni bulacağım',
    'kes sesini',
    'kimse seni istemiyor',
    'hesabını kapat',
    'zavallı yaratık',
    'beceriksiz',
    'ezik yine',
    'korkak tavuk',
    'midem bulanıyor',
    'i will find you',
    'fuck off',
    'fuck you',
    'shut up moron',
    'piece of shit',
    'pathetic loser',
    'miserable creature',
    'nobody wants you',
    'delete your account',
    'dumbass',
    'я тебя найду',
    'заткнись',
    'удали аккаунт',
    'жалкое существо',
    'жалкий неудачник',
  ],
  'SEX': [
    'numaranı ver güzelim',
    'bedenin hakkında',
    'özelden yazayım',
    'geceleri seni yalnız',
    'give me your number gorgeous',
    "won't let you sleep tonight",
    "what i'd do with that body",
    'deserve every dirty comment',
    'дай номер красавица',
    'спать не дам',
    'грязные комментарии',
    'с таким телом',
    'revenge porn',
    'intikam pornosu',
    'nude pics for money',
    'para karşılığı çıplak',
  ],
  'CSA': [
    'çocuk pornosu',
    'reşit olmayan çıplak',
    'child pornography',
    'nude minor',
    'детская порнография',
    'обнаженный несовершеннолетний',
    'child sexual abuse material',
  ],
  'VIO': [
    'işkence videosu',
    'kanlı ceset',
    'torture video',
    'bloody corpse',
    'видео пыток',
    'кровавый труп',
    'yüzünü dağıtırım',
    'smash your face',
    'лицо разобью',
  ],
  'THR': [
    'seni öldür',
    'seni vuracağım',
    'seni geberteceğim',
    'umarım geberirsin',
    'hesabını soracağız',
    'adresini biliyorum',
    'i will kill you',
    'i will shoot you',
    'i will hurt you',
    'smash your face',
    'drop dead',
    'make you pay',
    'i know your address',
    "we'll find you",
    'я тебя убью',
    'я тебя застрелю',
    'я причиню тебе вред',
    'лицо разобью',
    'ты сдохнешь',
    'знаю твой адрес',
    'заставим заплатить',
  ],
  'SELF': [
    'intihar edeceğim',
    'intihar yöntemi',
    'kendimi öldüreceğim',
    'kill myself',
    'suicide method',
    'how to suicide',
    'want to die tonight',
    'end my life',
    'покончить с собой',
    'хочу умереть',
    'метод самоубийства',
  ],
  'HATE': [
    'nazi',
    'ırkından nefret',
    'i hate your race',
    'ненавижу твою расу',
    'göçmenler insan değil',
    'hepsini ülkeden sür',
    'kadınların aklı',
    'engelliler topluma sadece yük',
    'immigrants are vermin',
    'should all be thrown out',
    "women aren't intelligent",
    'disabled people are nothing but a burden',
    'мигранты паразиты',
    'женщинам не хватает ума',
    'инвалиды только обуза',
    'чурки никогда',
  ],
  'EXT': [
    'terör örgütü',
    'terrorist attack how',
    'join the caliphate',
    'bomb making guide',
    'изготовление бомбы',
    'вступить в террор',
  ],
  'CRIME': [
    'silah satışı',
    'how to make a bomb',
    'illegal weapons for sale',
    'buy a gun without id',
    'изготовление оружия',
  ],
  'DRUG': [
    'uyuşturucu satışı',
    'selling cocaine',
    'how to cook meth',
    'mdma satış',
    'продажа кокаина',
    'варить мет',
  ],
  'SCAM': [
    'send bitcoin now',
    'garanti kazanç yatır',
    'western union urgent',
    'nft giveaway seed phrase',
    'отправь биткоин',
  ],
  'CYBER': [
    'steal passwords',
    'phishing kit',
    'ransomware for sale',
    'взлом аккаунта',
    'стиллер паролей',
  ],
  'PRIV': [
    'tc kimlik numaran',
    'doxx',
    'social security number is',
    'herkese anlatırım akıllı ol',
    'i know things about you',
    'everyone will hear them',
    'узнают все',
    'adresini biliyorum',
    'i know your address',
    'знаю твой адрес',
  ],
  'IMP': [
    'ben rektörüm',
    'official arucad admin',
    'я официальный админ',
  ],
  'SPAM': [
    'follow for follow spam',
    'mass dm promo',
    'криптосигналы бесплатно',
  ],
  'MISINFO': [
    'içme suyu zehirlendi kesin',
    'campus water is poisoned confirmed',
  ],
  'IP': [
    'full movie free download pirated',
    'crack adobe license',
  ],
  'ANIMAL': [
    'hayvan işkencesi videosu',
    'animal torture video',
    'видео пыток животных',
  ],
  'MINOR': [
    'meet kids alone privately',
    'reşit değilim buluşalım',
    'секретная встреча с ребёнком',
  ],
  // Political campaigning/recruitment. Names and news references alone are
  // intentionally absent; the server performs the contextual final check.
  'POL': [
    'genel seçimlerde oy verin',
    'akp kazanmalı',
    'chp kazanmalı',
    'partimize katıl',
    'vote republican',
    'vote democratic',
    'join our political party',
    'голосуйте за единую россию',
  ],
};

class ModerationResult {
  final bool allowed;
  final String? reason;
  const ModerationResult.allow()
      : allowed = true,
        reason = null;
  const ModerationResult.block(this.reason) : allowed = false;
}

ModerationResult moderateText(String text) {
  final normalized = _normalize(text);
  for (final entry in _blockedTerms.entries) {
    for (final term in entry.value) {
      final normalizedTerm = _normalize(term);
      if (normalizedTerm.isEmpty) continue;

      // The client is only a fast UX guard; the server owns contextual
      // moderation. Profanity and self-harm words are particularly unsafe
      // to decide here: casual exclamations/quotes need context, and a person
      // asking for help must reach the server's support flow without being
      // told they committed a violation.
      if (entry.key == 'PROF' || entry.key == 'SELF') continue;
      if (!_hasWholeExpression(normalized, normalizedTerm)) continue;

      final label = _categoryLabels[entry.key] ?? 'küfür veya saldırgan dil';
      return ModerationResult.block(
          'İçerik topluluk kurallarına aykırı olabilecek $label içeriyor. Lütfen düzenleyip tekrar dene.');
    }
  }
  return const ModerationResult.allow();
}

/// Whole token/phrase matching only. The former `compact.contains` check
/// found banned character sequences inside unrelated words. Deliberately
/// spaced single-word obfuscation is still recognised ("f u c k"), but
/// ordinary substrings and partial words cannot enforce.
bool _hasWholeExpression(String text, String term) {
  if (' $text '.contains(' $term ')) return true;
  if (term.contains(' ')) return false;

  final tokens = text.split(' ');
  for (var start = 0; start < tokens.length; start++) {
    if (tokens[start].runes.length != 1) continue;
    final buffer = StringBuffer();
    for (var i = start; i < tokens.length && tokens[i].runes.length == 1; i++) {
      buffer.write(tokens[i]);
      final candidate = buffer.toString();
      if (candidate == term) return true;
      if (candidate.length >= term.length) break;
    }
  }
  return false;
}

String _normalize(String value) {
  var out = value.toLowerCase();
  out = out.replaceAll(RegExp(r'[\u200B-\u200D\u2060\uFEFF\u180E]'), '');
  out = out.replaceAll('*', '').replaceAll('#', '').replaceAll('•', '');
  const leet = {
    'ç': 'c',
    'ğ': 'g',
    'ı': 'i',
    'ö': 'o',
    'ş': 's',
    'ü': 'u',
    '@': 'a',
    '0': 'o',
    '1': 'i',
    '3': 'e',
    '4': 'a',
    '5': 's',
    '7': 't',
  };
  leet.forEach((from, to) => out = out.replaceAll(from, to));
  const lookalikes = {
    'a': 'а',
    'e': 'е',
    'o': 'о',
    'p': 'р',
    'c': 'с',
    'x': 'х',
    'y': 'у',
    'k': 'к',
    'h': 'н',
    'b': 'в',
    'm': 'м',
    't': 'т',
  };
  lookalikes.forEach((from, to) => out = out.replaceAll(from, to));
  // Preserve letters (incl. Cyrillic) while turning punctuation/emoji into spaces.
  out = out.replaceAll(RegExp(r'[^\p{L}\p{N}]+', unicode: true), ' ');
  out = out.replaceAll(RegExp(r'\s+'), ' ').trim();
  // Letter stretched 3+ times for emphasis/evasion ("saaaalak") collapses to
  // one occurrence. A normal double letter ("kill", "will") only ever
  // repeats twice, so this never touches it (mirrors ModerationService).
  return out.replaceAllMapped(
      RegExp(r'(.)\1{2,}', unicode: true), (m) => m.group(1)!);
}

/// Throws if [text] fails the check — the convenient form for repository
/// methods that just want to reject and let the UI show the reason.
void assertTextAllowed(String text) {
  final result = moderateText(text);
  if (!result.allowed) throw ContentModerationException(result.reason!);
}
