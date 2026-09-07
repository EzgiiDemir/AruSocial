/// What a step's "Detay" action should do — kept to what's honestly
/// buildable without threading map/navigation dependencies through every
/// screen that can open the First 30 Days checklist:
/// - [service]: opens the real Service Detail screen for [refId] (a
///   `CampusService` id) — full in-app navigation.
/// - [list]: shows a bottom sheet backed by real live data (clubs or
///   sports, per [refId]).
/// - [info]: shows a bottom sheet with just [OnboardingStep.detail] — used
///   where there's no single real in-app destination (e.g. exploring the
///   city of Kyrenia).
enum OnboardingActionKind { service, list, info }

/// A single "First 30 Days" checklist item — grouped by when a new student
/// would realistically get to it, not a hard deadline.
class OnboardingStep {
  final String id;
  final String group;
  final String title;

  /// What this step is actually for and why it matters — shown before the
  /// student just taps a checkbox blind.
  final String detail;

  final OnboardingActionKind actionKind;

  /// Meaning depends on [actionKind]: a `CampusService` id for [service],
  /// `'clubs'`/`'sports'` for [list], unused for [info].
  final String? refId;

  /// Only meaningful for REST-backed steps (admin-controlled ordering);
  /// the hardcoded mock-mode const already lists itself in display order.
  final int sortOrder;

  /// Admin-only visibility flag; always true for public/mock-mode steps
  /// (the public endpoint never returns an inactive one).
  final bool active;

  const OnboardingStep({
    required this.id,
    required this.group,
    required this.title,
    required this.detail,
    this.actionKind = OnboardingActionKind.info,
    this.refId,
    this.sortOrder = 0,
    this.active = true,
  });

  factory OnboardingStep.fromJson(Map<String, dynamic> json) => OnboardingStep(
        id: json['id'] as String,
        group: json['group'] as String,
        title: json['title'] as String,
        detail: json['detail'] as String,
        actionKind: _actionKindFromApi(json['actionKind'] as String?),
        refId: json['refId'] as String?,
        sortOrder: (json['sortOrder'] as num?)?.toInt() ?? 0,
        active: json['active'] as bool? ?? true,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'groupLabel': group,
        'title': title,
        'detail': detail,
        'actionKind': switch (actionKind) {
          OnboardingActionKind.service => 'service',
          OnboardingActionKind.list => 'list',
          OnboardingActionKind.info => 'info',
        },
        'refId': refId,
        'sortOrder': sortOrder,
        'active': active,
      };
}

OnboardingActionKind _actionKindFromApi(String? value) => switch (value) {
      'service' => OnboardingActionKind.service,
      'list' => OnboardingActionKind.list,
      _ => OnboardingActionKind.info,
    };

const onboardingSteps = <OnboardingStep>[
  OnboardingStep(
    id: 'day1-orientation',
    group: '1. Gün',
    title: 'Kampüs oryantasyonuna katıl',
    detail:
        'Yeni öğrenciler için düzenlenen oryantasyon, kampüsün genel işleyişini, '
        'binaları ve ilk haftada kimden ne isteyeceğini anlatır. Tarih ve yer için '
        'Öğrenci İşleri ile iletişime geç.',
    actionKind: OnboardingActionKind.service,
    refId: 'student-affairs',
  ),
  OnboardingStep(
    id: 'day1-affairs',
    group: '1. Gün',
    title: 'Öğrenci İşleri ile tanış',
    detail:
        'Kayıt, ders seçimi, resmi belgeler ve mezuniyete kadar her idari süreçte '
        'ilk başvuracağın yer burası.',
    actionKind: OnboardingActionKind.service,
    refId: 'student-affairs',
  ),
  OnboardingStep(
    id: 'day1-id',
    group: '1. Gün',
    title: 'Öğrenci kimlik kartını al',
    detail:
        'Kimlik kartı kütüphane, atölye ve etkinlik girişleri için gerekli — '
        'Öğrenci İşleri üzerinden başvuruyorsun.',
    actionKind: OnboardingActionKind.service,
    refId: 'student-affairs',
  ),
  OnboardingStep(
    id: 'week1-department',
    group: '1. Hafta',
    title: 'Bölümünü ve binanı bul',
    detail:
        'Derslerinin ve atölyelerinin nerede olduğunu bilmek ilk haftanın en '
        'pratik kazanımı — Bina Dizini\'nden gerçek konum bilgisine bakabilirsin.',
  ),
  OnboardingStep(
    id: 'week1-advisor',
    group: '1. Hafta',
    title: 'Akademik danışmanınla tanış',
    detail:
        'Her öğrencinin bölümünden bir akademik danışmanı vardır; ders seçimi ve '
        'akademik plan için ona başvurursun.',
    actionKind: OnboardingActionKind.service,
    refId: 'academic-advising',
  ),
  OnboardingStep(
    id: 'week1-club',
    group: '1. Hafta',
    title: 'Bir kulübe göz at',
    detail:
        'ARUCAD\'da onlarca gerçek öğrenci kulübü var — fotoğraftan e-spora, '
        'dansa kadar. Birine göz atmak katılmayı garantilemez, ama başlangıç için iyi.',
    actionKind: OnboardingActionKind.list,
    refId: 'clubs',
  ),
  OnboardingStep(
    id: 'week1-garden',
    group: '1. Hafta',
    title: "Garden'ı keşfet",
    detail:
        'Kampüsün açık hava sosyal alanı — molalarda, sohbette ve bazı '
        'etkinliklerde buluşma noktası. Discover → Yerler üzerinden bulabilirsin.',
  ),
  OnboardingStep(
    id: 'week2-library',
    group: '2. Hafta',
    title: 'Kütüphaneyi ziyaret et',
    detail:
        'Sanat, tasarım ve iletişim odaklı kaynaklar, sessiz çalışma alanları ve '
        'dijital istasyonlar burada.',
    actionKind: OnboardingActionKind.service,
    refId: 'library',
  ),
  OnboardingStep(
    id: 'week2-sports',
    group: '2. Hafta',
    title: 'Bir spor etkinliğine katıl',
    detail:
        'Basketboldan bowling\'e kadar gerçek ARUCAD spor imkânları var — '
        'hangilerinin olduğuna bak.',
    actionKind: OnboardingActionKind.list,
    refId: 'sports',
  ),
  OnboardingStep(
    id: 'week2-social',
    group: '2. Hafta',
    title: 'Bir sosyal etkinliğe git',
    detail:
        'Home\'daki "Bugün" ve Sosyal sekmesindeki "Kampüste Şimdi" bölümleri '
        'güncel etkinlikleri gösterir.',
  ),
  OnboardingStep(
    id: 'week3-kyrenia',
    group: '3. Hafta',
    title: "Girne'yi keşfet",
    detail:
        'Kampüsün bulunduğu şehri tanımak da kampüs yaşamının bir parçası — '
        'bu adım için uygulama içinde tek bir gerçek hedef yok, kendi keşfin.',
  ),
  OnboardingStep(
    id: 'week3-community',
    group: '3. Hafta',
    title: 'Bir topluluğa katıl',
    detail:
        'Kulüplerden birine gerçekten katılmak (sadece göz atmak değil) — '
        'listeye bakıp "Katıl"a dokunabilirsin.',
    actionKind: OnboardingActionKind.list,
    refId: 'clubs',
  ),
  OnboardingStep(
    id: 'week4-career',
    group: '4. Hafta',
    title: 'Kariyer ofisiyle tanış',
    detail:
        'İş/staj fırsatları, kariyer etkinlikleri, portfolyo değerlendirmesi ve '
        'mezun ağı bağlantıları — ne zaman ihtiyacın olursa bilmen için şimdiden tanış.',
    actionKind: OnboardingActionKind.service,
    refId: 'career',
  ),
];
