"""Self-hosted semantic text signal: contrastive sentence embeddings.

The deterministic lexicon catches phrasings someone has written down. It
cannot catch paraphrase, and measured on the live API it published five
of nine realistic harmful posts — a paraphrased threat, targeted
harassment, sexual coercion, self-harm intent and a phishing message.

This layer answers the paraphrase half, and it borrows the idea that
rescued image moderation. CLIP only became usable when risky prompts had
to *compete* against accurate benign descriptions: Rodin's Age of Bronze
stopped reading as nudity once "a bronze sculpture of a nude human
figure" was on the board. The same applies here — each category has
several exemplar sentences, and they compete against exemplars of
ordinary campus writing, weighted towards the subjects that trip naive
classifiers at an art and design university: life drawing, war
photography, horror-film analysis, an addiction thesis, and
suicide-prevention outreach.

The score returned is a MARGIN, not a similarity: how much more a post
resembles the nearest category exemplar than the nearest benign one.
Raw similarity answers a different question and is exactly why a
single-label toxicity model was useless here — it scored an English
threat 0.0085 and a life-drawing announcement 0.3722.

Measured on text_benchmark.json (19 harmful, 20 safe, TR/EN/RU):

    harmful best margin   min -0.0182   median  0.3788
    safe    best margin   max -0.1295   median -0.4951
    gap +0.1113, 27 ms/text on CPU

Two harmful cases fall inside the gap (a bare slur, a coded drug offer).
Both are already covered by the lexicon, which is the point of running
the two layers together rather than choosing between them.

Thresholds are NOT here. This returns margins; Laravel decides.
"""

from __future__ import annotations

import re
import time
from dataclasses import dataclass

from app.config import Settings

CATEGORY_EXEMPLARS: dict[str, list[str]] = {
    "THR": [
        "Seni bulacağım ve canını yakacağım.",
        "Yarın okula silahla geleceğim, hepiniz göreceksiniz.",
        "I know where you live and you will regret it tomorrow.",
        "Я знаю, где ты живёшь, тебе будет больно.",
        # Indirect threats: no verb of violence, all menace. These were
        # measured scoring 0.0088-0.0113 against the original exemplars —
        # far below anything usable — because every listed example was
        # explicit and this register is not.
        "Dikkatli yürü, senin gibilerin başına kaza gelir.",
        "Çıkışta bekliyor olacağım, pişman edeceğim seni.",
        "Watch your back on the way home, accidents happen to people like you.",
        "It would be a shame if something happened to you after class.",
        # Menace with no verb at all — an arranged meeting the other
        # person has not agreed to, plus a promise they will understand
        # afterwards. Held-out testing missed this register entirely.
        "Kütüphaneden çıkarken beni göreceksin, o zaman anlarsın kiminle uğraştığını.",
        "Yalnız kaldığın bir an olacak, o zaman konuşuruz.",
        "You will find out what happens to people who say things like that.",
        # The indirect register in English and Russian. Both were missed
        # in the broad sweep while their Turkish equivalents were caught —
        # the exemplars had drifted Turkish-heavy, and the model can only
        # recognise a register it has been shown in that language.
        "Keep talking and someone will be waiting for you outside.",
        "Someone will be waiting for you after class, do not walk alone.",
        "Ещё раз откроешь рот — пожалеешь, я найду тебя после пар.",
        "Тебя будут ждать на выходе, лучше не ходи одна.",
    ],
    "HAR": [
        "Bu kız her gün rezil oluyor, sınıfta herkes ona gülsün.",
        "Hepimiz onun hesabına yazalım, silene kadar rahat bırakmayalım.",
        "Everyone screenshot her post and spam it until she deletes her account.",
        "Она уродина, давайте все её затравим.",
        # Covert mockery: recording or photographing someone in order to
        # circulate it and laugh. Nothing in it is abusive on its own —
        # the harm is entirely in the purpose — and two held-out cases of
        # exactly this shape published.
        "Tek başına otururken fotoğrafını çekip gruba atalım, hep komik oluyor.",
        "Onun aksanını taklit eden bir video yapalım, bir hafta paylaşalım.",
        "Record her answering in class and post it so she stops speaking up.",
    ],
    "SEX": [
        "Çok seksisin, seninle neler yapardım bir bilsen.",
        "Send me nudes and I will help you pass the exam.",
        "Vücudun hakkında konuşmak istiyorum, özelden yaz.",
        "Пришли голые фото, никто не узнает.",
        # Quid pro quo, which is the form this takes between a member of
        # staff and a student and the form that matters most on a campus.
        # It reads as an ordinary offer until you notice what is being
        # traded, and it scored 0.0017 against the explicit exemplars.
        "Notunu düzeltebilirim ama karşılığında benimle vakit geçirmen lazım.",
        "Akşam ofisime yalnız gel, o zaman geçer notunu konuşuruz.",
        "Come to my office alone tonight if you want that grade changed.",
        "I can make this exam problem disappear if you are nice to me.",
        # Veiled coercion. No proposition is stated; the exchange is
        # implied by "friendlier", "warmer", "just between us". This is
        # the register actually used between staff and students, and it
        # was missed.
        "Danışmanlığını kabul ederim ama bana karşı biraz daha sıcak davranman lazım.",
        "Aramızda kalsın, biraz daha yakın olursan bu mesele çözülür.",
        "If you want me to supervise you, you should be a lot friendlier in private.",
        # Open solicitation, which every exemplar above missed because they
        # are all about coercing one person. "Group sex, do u want to come
        # in" scored SEX **-0.121** — the model was reading it as further
        # from the sexual category than ordinary campus writing, because
        # nothing here looked like an invitation to strangers.
        "Grup seks yapmak isteyen var mı, akşam buluşalım.",
        "Bu gece tek gecelik bir şey arıyorum, isteyen yazsın.",
        "Group sex tonight, anyone want to come and join us?",
        "Who wants to hook up this evening, message me privately.",
        "Looking for casual sex, no strings, dm me if interested.",
        "Кто хочет секса сегодня вечером, пишите в личку.",
        "Ищу девушку для секса на одну ночь, пиши.",
    ],
    "SELF": [
        "Artık dayanamıyorum, bu gece her şeyi bitireceğim.",
        "Kendimi öldürmek istiyorum, kimse aramasın.",
        "Tonight I am going to end my life, please do not look for me.",
        "Я больше не хочу жить, это моё последнее сообщение.",
        # Farewell without the act named. Both the English and the Russian
        # cases of this shape were missed in the broad sweep — the most
        # serious misses in it, because the cost of missing one is not a
        # policy violation reaching a feed.
        "Kimseye yük olmak istemiyorum artık, bu akşam veda ediyorum.",
        "I have written the letters already, please do not call me tonight.",
        "I am saying goodbye, thank you for everything, do not worry about me.",
        "Я всё решил, завтра меня уже не будет, не ищите меня.",
        "Простите меня все, это моё прощание.",
    ],
    "SCAM": [
        "Kart bilgilerini şu linke gir, burs son gün!",
        "Hesabın kapatılacak, şifreni buradan doğrula.",
        "URGENT: verify your password at this link or your account closes.",
        "Введите данные карты по ссылке, чтобы получить стипендию.",
        # The campus-specific shape: a look-alike domain, a threat that a
        # record will be deleted, and a form asking for student details.
        # It reads like an administrative notice, which is the point.
        "Kaydın silinmemesi için öğrenci bilgilerini şu adrese gir: arucad-dogrulama.info",
        "Hesabın askıya alındı, öğrenci numaranla şu bağlantıdan doğrula.",
        "Your student record will be deleted unless you confirm your details at this address.",
    ],
    "HATE": [
        "Bu yabancılar pislik, defolup ülkelerine gitsinler.",
        "Onların burada yeri yok, hepsi aşağılık.",
        "These foreigners are vermin and should be thrown out.",
        "Эти приезжие — грязь, им здесь не место.",
        # Contempt aimed at where people come from or what they can pay,
        # phrased as a complaint about standards. No slur, and it was
        # missed — but "they lowered the level, keep away from them" is
        # the same exclusion with a respectable vocabulary.
        "Taşradan gelenler yüzünden bölümün seviyesi düştü, aralarına karışmayın.",
        "Burslu olanlar buraya ait değil, ortalamayı aşağı çekiyorlar.",
        "Those students drag the whole department down, they do not belong here.",
    ],
    "VIO": [
        "Onu yakalayıp kafasını duvara vuracağım, kanı aksın.",
        "Dişlerini dökene kadar döveceğim.",
        "I will smash his head until he bleeds.",
        "Разобью ему лицо, пусть кровь идёт.",
    ],

    # --- Categories added after a broad measurement -------------------
    #
    # The seven above were the only ones with exemplars, and a 48-case
    # sweep across all 21 defined categories found the consequence:
    # SPAM 0/2, IMP 0/2, POL 0/1 and IP 0/1 — not weak, absent. A
    # category the semantic layer has never been shown is a category it
    # cannot recognise, however obvious the text looks to a person.
    "SPAM": [
        "Takipçi satıyorum, 10K çok ucuz, DM at hemen, fırsatı kaçırma!!!",
        "Followers for sale, cheapest price, DM now, link in bio, limited offer!!!",
        "Продаю подписчиков, дёшево, пишите в личку, успей купить!!!",
    ],
    "IMP": [
        "Rektörlük adına duyuruyorum: yarın dersler iptal edilmiştir.",
        "Öğrenci İşleri resmî hesabıdır, kaydını doğrulamak için şifreni gönder.",
        "This is the official Student Affairs account, send your password to verify.",
        "Пишу от имени деканата: все занятия завтра отменяются.",
    ],
    "POL": [
        "Pazar günü sandığa gidin ve şu partiye oy verin, kampüste kampanya yapıyoruz.",
        "Vote for this party on Sunday, we are campaigning across the campus.",
        "Голосуйте за эту партию в воскресенье, мы ведём кампанию в кампусе.",
    ],
    "IP": [
        "Bütün ders kitaplarının taranmış hâlini paylaşıyorum, telifli olsa da olur.",
        "I am sharing scanned copies of all the textbooks, ignore the copyright.",
        "Выкладываю сканы всех учебников, авторские права неважны.",
    ],
    "MISINFO": [
        "Yarınki sınav iptal edildi, kimse gelmesin, bana böyle söylendi.",
        "The dorm water is poisoned and the university is covering it up.",
        "Экзамен отменили, никто не приходите, мне так сказали.",
    ],
    "CRIME": [
        "Gece laboratuvarın kilidini kırıp ekipmanı alalım, kimse görmez.",
        "I can get into the staff office after ten, we take the equipment and sell it.",
        "Взломаем замок лаборатории ночью и вынесем оборудование.",
    ],
    "CYBER": [
        "Hocanın hesabına girdim, notları değiştirebilirim, isteyen yazsın.",
        "I have the admin password for the student portal, tell me what to change.",
        "У меня есть пароль администратора портала, скажи, что поменять.",
    ],
    "DRUG": [
        "Yeşil mal var, kampüs arkasında buluşalım, fiyatı konuşuruz.",
        "Selling pills before the party, message me, cash only, no questions.",
        "Есть товар, встретимся за кампусом, о цене договоримся.",
    ],
    "ANIMAL": [
        "Kampüsteki kedileri zehirleyelim, çok çoğaldılar.",
        "We kicked the stray dog behind the studio and filmed it.",
        "Давайте потравим кошек в кампусе, их слишком много.",
    ],
    "PRIV": [
        "Ayşe'nin numarası şu, arayıp rahatsız edin bakalım.",
        "Here is his home address and his mother's phone number, do what you want.",
        "Вот её адрес и телефон матери, делайте что хотите.",
    ],
}

BENIGN_EXEMPLARS: list[str] = [
    "Yarın kütüphanede birlikte ders çalışalım mı?",
    "Cüzdanımı kantinde kaybettim, bulan olursa yazsın.",
    "Fotoğrafçılık kulübü yeni üye alıyor, cuma toplantı var.",
    "Bu sınav çok zordu, moralim bozuldu.",
    "Ring saatleri değişti mi, sabah servisini kaçırdım?",
    "Bu dönem nü etüt dersi var, modelle çalışacağız, anatomi için faydalı.",
    "Rodin'in Öpücük heykeli üzerine sunum hazırlıyorum, çıplak figür analizi var.",
    "My thesis covers the nude in twentieth century photography and gallery censorship.",
    "Sinema dersinde şiddet sahnelerinin kurgusunu analiz ediyoruz, korku türü üzerine.",
    "Savaş fotoğrafçılığı sergisi açıldı, silah ve çatışma görüntüleri tartışılacak.",
    "For our game design project the player fights monsters with a sword.",
    "Psikolojik destek birimi yarın stres ve intihar önleme semineri düzenliyor.",
    "If anyone is struggling this exam season, counselling is free and confidential.",
    "Tez konum uyuşturucu bağımlılığı ve gençler üzerindeki etkisi.",
    "The lecture covered how opioids affect the nervous system.",
    "Münazara kulübü göçmen politikaları üzerine tartışma düzenliyor.",
    "Кто-нибудь знает, во сколько завтра открывается библиотека?",
    "Does anyone have last year's notes for the typography module?",
    "Yönetimin bu kararını hiç doğru bulmuyorum, öğrencilere danışılmadı.",
    "Kafeteryadaki yemekler bu dönem berbat ve fiyatlar yine arttı.",
    # Reassurance and advice about someone's performance. This is the
    # nearest benign neighbour of harassment — both talk about a person
    # struggling in front of a class — and without it a post telling
    # someone a trembling voice is normal scored HAR +0.1758, above the
    # block line, while four genuinely harmful posts sat below 0.09.
    # A false positive that outscores real abuse is the clearest possible
    # sign the benign side is underspecified.
    "Sunum yaparken sesin titriyorsa bu çok normal, herkes heyecanlanır.",
    "İlk sunumunda zorlanman doğal, pratikle çok daha rahat olacaksın.",
    "Nobody laughed at your presentation, everyone is nervous their first time.",
    # Ordinary administrative traffic that mentions money, accounts and
    # grades — the vocabulary scams and coercion also use.
    "Notuma itiraz etmek istiyorum, hocayla nasıl randevu alabilirim?",
    "Harç iadesi için öğrenci işleri hangi belgeleri istiyor?",
    "Güvenlik birimi otoparkta gece devriyelerini artırdı.",
    "Boks kulübü yeni sezon çalışmalarına başlıyor, ring dövüşü var.",
    # Grown alongside the category exemplars above. Each of these is the
    # innocent neighbour of something just added: arranging to meet
    # someone, recording a lecture, asking about supervision, a real
    # account problem, and students arriving from elsewhere. Adding the
    # harmful side without these is how a classifier starts refusing
    # ordinary campus administration.
    "Çıkışta buluşalım, dersten sonra birlikte gideriz.",
    "Dersi ses kaydına alsam olur mu, hoca izin veriyor mu?",
    "Sunumu videoya çekelim mi, sonra birlikte izleyip çalışırız.",
    "Tez danışmanı olarak hangi hocaya başvurmak mantıklı olur?",
    "Öğrenci bilgi sistemine giriş yapamıyorum, şifremi kim sıfırlıyor?",
    "Kütüphane hesabım askıya alınmış, borcumu nereye ödeyeceğim?",
    "Farklı şehirlerden gelen arkadaşlar için barınma desteği nasıl işliyor?",
    "Burslu öğrenciler için ayrı bir oryantasyon yapılıyor mu?",
    "Devamsızlık sınırını aşarsam dersten kalır mıyım?",

    # --- Language balance ------------------------------------------------
    #
    # The benign side had drifted almost entirely Turkish, and a broad
    # sweep showed what that costs: an English orientation announcement
    # and a Russian "what time does the library open" were both REFUSED.
    # A safe post in a language with no close benign exemplar has nothing
    # to lose to, so it scores relatively higher on whatever risky
    # category it resembles least badly.
    #
    # This is the exact mechanism that blocked Rodin before sculpture was
    # named, applied to language instead of medium.
    "What time does the library open tomorrow? I need to study.",
    "Does anyone have the reading list for this module?",
    "The international office is running an orientation for new students.",
    "I am waiting outside with the prints, see you at five.",
    "My grant payment is late this month, did anyone else have this?",
    "I forgot my portal password again, how do I reset it?",
    "If you are struggling this term, the counselling service is free.",
    "The cafeteria prices went up again and the food is worse.",
    "Во сколько завтра открывается библиотека? Нужно готовиться к экзамену.",
    "У кого-нибудь есть конспекты за прошлый семестр?",
    "Завтра меня не будет на паре, я в отъезде, кто законспектирует?",
    "Буду ждать тебя после пар у входа, вместе пойдём в библиотеку.",
    "В нашей группе ребята из разных стран, это очень интересно.",
    "Кто знает, где обновить банковские реквизиты для стипендии?",
    "Я очень устал за этот семестр, но скоро каникулы.",
    "Студенческий совет проводит собрание в пятницу, приходите.",

    # Short clauses, the kind that sit *inside* a long post.
    #
    # Added when scoring moved from whole posts to clauses. Every benign
    # exemplar above is a full sentence, so an ordinary fragment resembled
    # none of them, its benign baseline collapsed, and the margin inflated
    # — an exhibition announcement was refused as DRUG and a warning about
    # fake emails was held as IMP, purely because their clauses were short.
    # The baseline a clause is judged against has to be other clauses.
    "Program panoda asılı, bakabilirsiniz.",
    "Herkes gelsin, katılım ücretsiz.",
    "Toplantı saat üçte başlıyor.",
    "Son başvuru günü cuma, geç kalmayın.",
    "Kontenjan sınırlı, erken başvurun.",
    "Detaylar bölüm sekreterliğinde mevcut.",
    "Sergi açılışı cuma akşamı yapılacak.",
    "Malzeme listesi panoda yer alıyor.",
    "Yarın dersimiz iptal edildi.",
    "Notlarımı paylaşabilirim isteyene.",
    "Kayıt haftası pazartesi başlıyor.",
    "Şüpheli e-postaları bildirin lütfen.",
    "Üniversite asla şifrenizi istemez.",
    "Bu ödev beni bitirdi resmen.",
    "Boks maçı bu akşam salonda.",

    "The schedule is on the noticeboard.",
    "Everyone is welcome, entry is free.",
    "The session starts at two o'clock.",
    "Applications close on Friday.",
    "Places are limited, apply early.",
    "Details are available from the office.",
    "The exhibition opens on Friday evening.",
    "The materials list is on the board.",
    "Tomorrow's class has been cancelled.",
    "I can share my notes with anyone who needs them.",
    "Registration week opens on Monday.",
    "Please report anything suspicious.",
    "The university will never ask for your password.",
    "This assignment has completely finished me off.",
    "Bring your own kit to training.",

    "Расписание висит на доске объявлений.",
    "Вход свободный, приходите все.",
    "Занятие начинается в два часа.",
    "Приём заявок заканчивается в пятницу.",
    "Количество мест ограничено.",
    "Подробности можно узнать в деканате.",
    "Открытие выставки в пятницу вечером.",
    "Список материалов есть на доске.",
    "Завтра занятие отменяется.",
    "Могу поделиться конспектами.",
    "Неделя регистрации начинается в понедельник.",
    "Сообщайте о подозрительных письмах.",
    "Университет никогда не запросит ваш пароль.",
    "Это задание меня совершенно доконало.",
    "Не забудьте студенческий билет.",

    # Ordinary invitations.
    #
    # Added with the sexual-solicitation exemplars, and needed because of
    # them: those are all shaped like invitations ("anyone want to come and
    # join us?"), so without a benign counterpart the model learned the
    # *shape* rather than the content. "Anyone want to come along with me?"
    # — a clause from an announcement about a sexual-health seminar —
    # scored SEX 0.142 and held the announcement.
    "Anyone want to come along with me?",
    "Who wants to grab a coffee after the lecture?",
    "We are going to the exhibition tonight, anyone want to join?",
    "Anyone up for the cinema this evening?",
    "Message me if you want to come, there is space in the car.",
    "Kim gelmek ister, akşam sergiye gidiyoruz.",
    "İsteyen yazsın, kütüphanede yer tutuyorum.",
    "Benimle gelmek isteyen var mı, kantine iniyorum.",
    "Bu akşam buluşalım mı, film izleriz.",
    "Gelmek isteyenler saat altıda kapıda olsun.",
    "Кто хочет пойти со мной на выставку сегодня?",
    "Пишите, если хотите присоединиться, место есть.",
    "Кто хочет кофе после лекции?",
    "Давайте встретимся вечером, посмотрим фильм.",
    "Желающие могут прийти к шести к главному входу.",

    # Practical / workshop register.
    #
    # A message about a sticking lock, a borrowed key, a blade for cutting
    # card and forcing something until it breaks read as CRIME 0.17 and
    # HATE 0.15 — high enough to remove it — because nothing benign in this
    # list talks about tools, damage or entry. At a design school this is
    # among the commonest things students write to each other.
    "Atölye anahtarını güvenliğe bıraktım, kilit biraz sıkışıyor.",
    "Maket bıçağı körelmiş, yeni jilet aldım kesim için.",
    "Zorlamayın yoksa menteşe kırılacak, teknik işler bakacak.",
    "El aletlerini dolaba kaldırdım, anahtarı ben aldım.",
    "Kapı kilidi bozuk, içeride kaldık dün gece.",
    "I left the studio key with security, the lock sticks a bit.",
    "The blade is blunt, I bought a new one for cutting card.",
    "Do not force it or the hinge will break, maintenance is looking at it.",
    "I put the hand tools back in the cabinet and took the key.",
    "The door lock is broken and we got locked in last night.",
    "Ключ от мастерской я сдал охране, замок немного заедает.",
    "Лезвие затупилось, купил новое для резки картона.",
    "Не давите, иначе петля сломается, техслужба посмотрит.",
    "Я убрал инструменты в шкаф и забрал ключ.",
    "Дверной замок сломан, мы вчера остались внутри.",
]


@dataclass
class TextPrediction:
    margins: dict[str, float]
    model: str
    model_version: str
    latency_ms: int
    segments_scored: int = 1
    evidence: str = ""


# Sentence terminators across the three languages, plus newlines and the
# semicolon, which Russian and Turkish both use to join full clauses.
_SENTENCE_SPLIT = re.compile(r"(?<=[.!?…;])\s+|\n+")

# Below this a fragment is not a clause — "Tamam.", "Ок.", "Right." — and
# scoring it adds noise without adding meaning. A short fragment also has
# the weakest benign baseline, so it is exactly where a spurious margin
# appears; 30 characters is roughly the shortest real statement a student
# writes, and the whole text is scored regardless.
_MIN_SEGMENT_CHARS = 30

# A post long enough to hide something in. Beyond this many segments the
# extra ones are cropped rather than paid for on every request.
_MAX_SEGMENTS = 40


def split_sentences(text: str) -> list[str]:
    """Split a post into scoreable clauses.

    Whole-text embedding averages a post into one vector, so a threat in
    the middle of an ordinary announcement is averaged away with it —
    measured at a 49-173% margin drop, enough to flip the top category to
    one that fires nothing. Reading clause by clause is what makes the
    position of the harmful sentence stop mattering.
    """
    parts = [p.strip() for p in _SENTENCE_SPLIT.split(text) if p and p.strip()]
    kept = [p for p in parts if len(p) >= _MIN_SEGMENT_CHARS]

    # A single short sentence is its own best representation.
    if not kept:
        return []

    return kept[:_MAX_SEGMENTS]


class SemanticTextClassifier:
    def __init__(self, settings: Settings):
        self.s = settings
        self._tok = None
        self._model = None
        self._cat_vecs: dict[str, object] = {}
        self._benign_vecs = None
        self._load_error: str | None = None

    def load(self) -> None:
        if self._model is not None or not self.s.text_enabled:
            return
        try:
            from transformers import AutoModel, AutoTokenizer

            tok = AutoTokenizer.from_pretrained(
                self.s.text_model_id, revision=self.s.text_model_revision or None
            )
            model = AutoModel.from_pretrained(
                self.s.text_model_id, revision=self.s.text_model_revision or None
            )
            model.eval()

            self._tok, self._model = tok, model
            # Exemplar embeddings are fixed, so they are computed once at
            # startup. Doing it per request would make every post pay for
            # 100+ sentences that never change.
            self._cat_vecs = {c: self._embed(v) for c, v in CATEGORY_EXEMPLARS.items()}
            self._benign_vecs = self._embed(BENIGN_EXEMPLARS)
            self._load_error = None
        except Exception as exc:  # noqa: BLE001
            self._model = None
            self._load_error = f"{type(exc).__name__}: {exc}"

    def _embed(self, texts: list[str]):
        import torch

        batch = self._tok(texts, padding=True, truncation=True, max_length=256, return_tensors="pt")
        with torch.no_grad():
            out = self._model(**batch).last_hidden_state
        mask = batch["attention_mask"].unsqueeze(-1).float()
        pooled = (out * mask).sum(1) / mask.sum(1).clamp(min=1e-9)
        return pooled / pooled.norm(dim=-1, keepdim=True)

    def embed_texts(self, texts: list[str]) -> list[list[float]]:
        """Unit-length embeddings for arbitrary text.

        The same mean-pooled, L2-normalised vectors the category margins
        are computed from, exposed so Laravel can rank knowledge pages
        against a question by cosine similarity. Because they are
        normalised, a dot product IS the cosine — the caller does not have
        to normalise again, and must not.

        Raises if the model is not loaded, rather than returning an empty
        list: a caller has to be able to tell "nothing matched" from
        "nothing was compared".
        """
        if not self.ready:
            raise RuntimeError(self._load_error or "text model not loaded")

        return [[float(x) for x in row] for row in self._embed(texts)]

    @property
    def enabled(self) -> bool:
        return bool(self.s.text_enabled)

    @property
    def ready(self) -> bool:
        return self._model is not None

    @property
    def load_error(self) -> str | None:
        return self._load_error

    def predict(self, text: str) -> TextPrediction:
        if not self.ready:
            raise RuntimeError(self._load_error or "text model not loaded")

        started = time.perf_counter()

        # The whole text is always scored, so a post whose harm is spread
        # across sentences rather than concentrated in one still registers.
        # Clauses are scored alongside it, and each category keeps whichever
        # reading found it strongest.
        segments = split_sentences(text)
        units = [text] + [s for s in segments if s != text]
        vectors = self._embed(units)

        # One benign baseline per unit: a clause that reads like ordinary
        # campus writing has to score against ordinary campus writing, not
        # against the whole post's average.
        benign_per_unit = (vectors @ self._benign_vecs.t()).max(dim=1).values

        margins: dict[str, float] = {}
        best_unit: dict[str, int] = {}
        for c, vec in self._cat_vecs.items():
            per_unit = (vectors @ vec.t()).max(dim=1).values - benign_per_unit
            index = int(per_unit.argmax())
            margins[c] = round(float(per_unit[index]), 4)
            best_unit[c] = index

        latency_ms = int((time.perf_counter() - started) * 1000)

        # Which clause drove the highest margin. A moderator reviewing a
        # 300-word post should not have to guess which sentence was read.
        top = max(margins, key=lambda c: margins[c])
        evidence = units[best_unit[top]] if margins else ""

        return TextPrediction(
            margins=margins,
            model=self.s.text_model_id,
            model_version=self.s.text_model_revision or "unpinned",
            latency_ms=latency_ms,
            segments_scored=len(units),
            evidence=evidence[:240],
        )
