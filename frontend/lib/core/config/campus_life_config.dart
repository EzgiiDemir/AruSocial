import '../models/content_block.dart';

/// Seed content for Discover's "Clubs", "Sports" and "Campus Services"
/// sections — the real starting data an admin would load into a CMS. From
/// here on, `AdminContentStore` (not these consts directly) is the actual
/// source of truth the app reads from: these lists only seed it the first
/// time the app runs, and every field below is editable at runtime from
/// the in-app Admin Panel instead of requiring a Dart code change.
class CampusClub {
  final String id;
  final String name;
  final String category;
  final String description;

  /// Optional rich, block-editor-authored long-form content — shown on the
  /// club's detail screen when non-empty. [description] stays the short
  /// plain-text summary used everywhere else (list rows, Ask ARUCAD
  /// context) — a block list doesn't belong in a one-line subtitle, so
  /// these two are deliberately separate rather than one field doing both.
  final List<ContentBlock> body;

  const CampusClub({
    required this.id,
    required this.name,
    required this.category,
    required this.description,
    this.body = const [],
  });

  factory CampusClub.fromJson(Map<String, dynamic> json) => CampusClub(
        id: json['id'] as String,
        name: json['name'] as String,
        category: json['category'] as String,
        description: json['description'] as String,
        body: blocksFromJson(json['body']),
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'category': category,
        'description': description,
        if (body.isNotEmpty) 'body': blocksToJson(body),
      };
}

const campusClubs = <CampusClub>[
  CampusClub(id: 'club-architecture', name: 'Architecture Club', category: 'Design', description: 'Mimari geziler, atölyeler ve öğrenci proje eleştirileri.'),
  CampusClub(id: 'club-art', name: 'Art Club', category: 'Art', description: 'Karma teknik atölyeler ve öğrenci sergileri.'),
  CampusClub(id: 'club-blender', name: 'Blender Modelling & Design Club', category: 'Digital', description: '3D modelleme ve dijital tasarım pratiği.'),
  CampusClub(id: 'club-bouldering', name: 'Bouldering Club', category: 'Sports', description: 'Kaya tırmanışı meraklıları için düzenli çıkışlar.'),
  CampusClub(id: 'club-charity', name: 'Charity Club', category: 'Community', description: 'Sosyal sorumluluk ve bağış projeleri.'),
  CampusClub(id: 'club-cinema', name: 'Cinema Club', category: 'Film', description: 'Film gösterimleri ve tartışma geceleri.'),
  CampusClub(id: 'club-comic', name: 'Comic Book & Anime Club', category: 'Culture', description: 'Çizgi roman ve anime kültürü etkinlikleri.'),
  CampusClub(id: 'club-dance', name: 'Dance Club', category: 'Performance', description: 'Farklı dans stillerinde açık provalar.'),
  CampusClub(id: 'club-drama', name: 'Drama Club', category: 'Performance', description: 'Oyunculuk atölyeleri ve sahne prodüksiyonları.'),
  CampusClub(id: 'club-esports', name: 'E-Sports & Game Design Club', category: 'Digital', description: 'Turnuvalar ve oyun tasarımı üzerine buluşmalar.'),
  CampusClub(id: 'club-fashion', name: 'Fashion Club', category: 'Design', description: 'Moda tasarımı ve stil projeleri.'),
  CampusClub(id: 'club-graffiti', name: 'Graffiti Club', category: 'Art', description: 'Sokak sanatı teknikleri ve ortak duvar projeleri.'),
  CampusClub(id: 'club-hiphop', name: 'Hip-Hop Club', category: 'Performance', description: 'Hip-hop dans ve müzik kültürü.'),
  CampusClub(id: 'club-martial-arts', name: 'Martial Arts Club', category: 'Sports', description: 'Dövüş sanatları eğitimi ve pratik seansları.'),
  CampusClub(id: 'club-music', name: 'Music Club', category: 'Performance', description: 'Prova, jam session ve küçük performanslar.'),
  CampusClub(id: 'club-nature-sports', name: 'Nature Sports Club', category: 'Sports', description: 'Doğa yürüyüşü ve outdoor aktiviteler.'),
  CampusClub(id: 'club-photography', name: 'Photography Club', category: 'Art', description: 'Fotoğraf yürüyüşleri ve teknik atölyeler.'),
  CampusClub(id: 'club-quality', name: 'Quality Club', category: 'Community', description: 'Kampüs kalite ve erişilebilirlik projelerine gönüllü katkı.'),
];

class CampusSport {
  final String id;
  final String name;
  final String facility;

  /// Sign-up email for this specific sport — left null (rather than
  /// guessed) until an admin enters one; the UI falls back to the general
  /// student-support address when it's not set.
  final String? contact;

  const CampusSport(
      {required this.id, required this.name, required this.facility, this.contact});

  factory CampusSport.fromJson(Map<String, dynamic> json) => CampusSport(
        id: json['id'] as String,
        name: json['name'] as String,
        facility: json['facility'] as String,
        contact: json['contact'] as String?,
      );

  Map<String, dynamic> toJson() =>
      {'id': id, 'name': name, 'facility': facility, if (contact != null) 'contact': contact};
}

const campusSports = <CampusSport>[
  CampusSport(id: 'sport-basketball', name: 'Basketbol', facility: 'Spor Salonu'),
  CampusSport(id: 'sport-3x3', name: '3×3 Basketbol', facility: 'Açık Saha'),
  CampusSport(id: 'sport-tennis', name: 'Tenis', facility: 'Tenis Kortu'),
  CampusSport(id: 'sport-futsal', name: 'Futsal', facility: 'Spor Salonu'),
  CampusSport(id: 'sport-football', name: 'Futbol', facility: 'Açık Saha'),
  CampusSport(id: 'sport-table-tennis', name: 'Masa Tenisi', facility: 'Spor Salonu'),
  CampusSport(id: 'sport-bowling', name: 'Bowling', facility: 'Kampüs Dışı Etkinlik'),
  CampusSport(id: 'sport-dart', name: 'Dart', facility: 'Sosyal Alan'),
  CampusSport(id: 'sport-billiards', name: 'Bilardo', facility: 'Sosyal Alan'),
];

/// One of ARUCAD's real, always-on student-support services — the "Help"
/// hub students land on when they don't know who to ask.
///
/// [building]/[floor]/[room]/[contactPerson] exist so a service can carry
/// real "where inside the building" detail (the "360 → floor → room →
/// person" flow) once an admin has entered it — left null here rather than
/// guessing a real room number that isn't actually verified.
class CampusService {
  final String id;
  final String title;
  final String category;
  final String description;
  final String contact;
  final String? building;
  final String? floor;
  final String? room;
  final String? contactPerson;

  /// "What can we help with" — concrete sub-topics a student would search
  /// for (e.g. "Ders Kaydı", "Öğrenci Kimlik Kartı"), turning a one-line
  /// description into something scannable on the service's detail screen.
  final List<String> topics;

  /// Free-text opening hours (e.g. "Hafta içi 09:00–17:00") — left null
  /// rather than guessing when not actually known.
  final String? hours;

  /// Same rich-content/short-summary split as `CampusClub.body` — see there.
  final List<ContentBlock> body;

  const CampusService({
    required this.id,
    required this.title,
    required this.category,
    required this.description,
    required this.contact,
    this.building,
    this.floor,
    this.room,
    this.contactPerson,
    this.topics = const [],
    this.hours,
    this.body = const [],
  });

  factory CampusService.fromJson(Map<String, dynamic> json) => CampusService(
        id: json['id'] as String,
        title: json['title'] as String,
        category: json['category'] as String,
        description: json['description'] as String,
        contact: json['contact'] as String,
        building: json['building'] as String?,
        floor: json['floor'] as String?,
        room: json['room'] as String?,
        contactPerson: json['contactPerson'] as String?,
        topics: (json['topics'] as List<dynamic>?)?.cast<String>() ?? const [],
        hours: json['hours'] as String?,
        body: blocksFromJson(json['body']),
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'title': title,
        'category': category,
        'description': description,
        'contact': contact,
        if (building != null) 'building': building,
        if (floor != null) 'floor': floor,
        if (room != null) 'room': room,
        if (contactPerson != null) 'contactPerson': contactPerson,
        if (topics.isNotEmpty) 'topics': topics,
        if (hours != null) 'hours': hours,
        if (body.isNotEmpty) 'body': blocksToJson(body),
      };
}

const campusServices = <CampusService>[
  CampusService(
    id: 'student-affairs',
    title: 'Öğrenci İşleri (Student Affairs)',
    category: 'İdari',
    description:
        'Kayıt, akademik süreçler, öğrenci kimlik kartı, ders kaydı ve mezuniyete kadar tüm resmi süreçlerde danışmanlık sağlar.',
    contact: 'destek@arucad.edu.tr',
    topics: ['Kayıt', 'Öğrenci Kimlik Kartı', 'Ders Kaydı', 'Resmi Belgeler', 'Mezuniyet'],
  ),
  CampusService(
    id: 'academic-advising',
    title: 'Akademik Danışmanlık',
    category: 'Akademik',
    description: 'Her öğrencinin bölümünden bir akademik danışmanı vardır; ders seçimi ve akademik plan için başvurulur.',
    contact: 'destek@arucad.edu.tr',
    topics: ['Ders Seçimi', 'Akademik Plan', 'Danışman Atama'],
  ),
  CampusService(
    id: 'pdr',
    title: 'Psikolojik Danışmanlık Merkezi (PDR)',
    category: 'Wellbeing',
    description:
        'Bireysel ve grup danışmanlığı; akademik, sosyal, duygusal ve kariyer konularında gizlilik esaslı, gönüllü destek.',
    contact: 'destek@arucad.edu.tr',
    topics: ['Bireysel Danışmanlık', 'Grup Danışmanlığı', 'Gizlilik', 'Kariyer Danışmanlığı'],
  ),
  CampusService(
    id: 'career',
    title: 'Kariyer ve Mezun Ofisi',
    category: 'Kariyer',
    description: 'İş/staj fırsatları, kariyer etkinlikleri, portfolyo değerlendirmesi ve mezun ağı bağlantıları.',
    contact: 'destek@arucad.edu.tr',
    topics: ['İş/Staj Fırsatları', 'Kariyer Etkinlikleri', 'Portfolyo Değerlendirmesi', 'Mezun Ağı'],
  ),
  CampusService(
    id: 'library',
    title: 'Kütüphane',
    category: 'Akademik',
    description: 'Sanat, tasarım ve iletişim odaklı kaynaklar, sessiz çalışma alanları ve dijital çalışma istasyonları.',
    contact: 'kutuphane@arucad.edu.tr',
    topics: ['Kaynak Ödünç Alma', 'Sessiz Çalışma Alanları', 'Dijital İstasyonlar'],
  ),
  CampusService(
    id: 'international',
    title: 'Uluslararası Öğrenci Ofisi',
    category: 'International',
    description: 'Vize/ikamet süreçleri, kayıt, ulaşım ve yeni gelen uluslararası öğrencilerin kampüse uyumu.',
    contact: 'international@arucad.edu.tr',
    topics: ['Vize/İkamet', 'Kayıt', 'Ulaşım', 'Kampüse Uyum'],
  ),
  CampusService(
    id: 'dormitory',
    title: 'Yurt (Dormitory)',
    category: 'Konaklama',
    description: 'Oda/bina bilgisi, ortak yaşam düzeni, bakım talepleri ve yurt duyuruları.',
    contact: 'destek@arucad.edu.tr',
    topics: ['Oda/Bina Bilgisi', 'Bakım Talepleri', 'Yurt Duyuruları'],
  ),
  CampusService(
    id: 'it',
    title: 'Bilgi İşlem (IT)',
    category: 'Teknik',
    description: 'Kampüs ağı, e-posta/sistem erişimi ve teknik destek talepleri.',
    contact: 'it@arucad.edu.tr',
    topics: ['Kampüs Ağı', 'E-posta/Sistem Erişimi', 'Teknik Destek'],
  ),
  CampusService(
    id: 'accessibility',
    title: 'Engelli Öğrenci Destek Birimi',
    category: 'Erişilebilirlik',
    description: 'Erişilebilir rota, sınıf/etkinlik düzenlemeleri ve bireysel destek ihtiyaçları için başvuru noktası.',
    contact: 'destek@arucad.edu.tr',
    topics: ['Erişilebilir Rota', 'Sınıf/Etkinlik Düzenlemeleri', 'Bireysel Destek'],
  ),
  CampusService(
    id: 'lost-found',
    title: 'Kayıp & Bulunan',
    category: 'Yardım',
    description: 'Kampüste kaybolan veya bulunan eşyalar için başvuru.',
    contact: 'destek@arucad.edu.tr',
    topics: ['Kayıp Eşya Bildirimi', 'Bulunan Eşya Teslimi'],
  ),
];

/// One venue's menu for one specific calendar day — lets a student pick a
/// date and see exactly what's served, at what price, that day, instead of
/// a single flat "today's menu" list that goes stale.
class DailyMenu {
  final DateTime date;
  final List<String> items;
  final String? price;
  final String? hours;

  DailyMenu({required DateTime date, this.items = const [], this.price, this.hours})
      : date = DateTime(date.year, date.month, date.day);

  factory DailyMenu.fromJson(Map<String, dynamic> json) => DailyMenu(
        date: DateTime.parse(json['date'] as String),
        items: (json['items'] as List<dynamic>?)?.cast<String>() ?? const [],
        price: json['price'] as String?,
        hours: json['hours'] as String?,
      );

  Map<String, dynamic> toJson() => {
        'date': date.toIso8601String(),
        if (items.isNotEmpty) 'items': items,
        if (price != null) 'price': price,
        if (hours != null) 'hours': hours,
      };
}

/// A real campus food spot and its admin-entered menu — starts with only
/// the venue name filled in (no invented hours or menu items) until an
/// admin actually enters them, same honesty rule as `DirectoryEntry`.
///
/// [menuFileUrl] is a direct link to the official uploaded monthly menu
/// (e.g. a PDF/image an admin hosts) that a student can open or download —
/// a real, honest alternative to [dailyMenus] for venues where the admin
/// would rather post the source file than re-type it into the calendar.
/// There's no AI parsing of that file in this build: turning an uploaded
/// menu file into structured [dailyMenus] automatically would need a real
/// backend, which this prototype doesn't have yet.
class CampusFoodVenue {
  final String id;
  final String name;
  final String? hours;
  final List<DailyMenu> dailyMenus;
  final String? menuFileUrl;

  const CampusFoodVenue({
    required this.id,
    required this.name,
    this.hours,
    this.dailyMenus = const [],
    this.menuFileUrl,
  });

  DailyMenu? menuForDay(DateTime day) {
    for (final m in dailyMenus) {
      if (m.date.year == day.year && m.date.month == day.month && m.date.day == day.day) {
        return m;
      }
    }
    return null;
  }

  factory CampusFoodVenue.fromJson(Map<String, dynamic> json) => CampusFoodVenue(
        id: json['id'] as String,
        name: json['name'] as String,
        hours: json['hours'] as String?,
        dailyMenus: (json['dailyMenus'] as List<dynamic>?)
                ?.map((e) => DailyMenu.fromJson(e as Map<String, dynamic>))
                .toList() ??
            const [],
        menuFileUrl: json['menuFileUrl'] as String?,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        if (hours != null) 'hours': hours,
        if (dailyMenus.isNotEmpty) 'dailyMenus': dailyMenus.map((m) => m.toJson()).toList(),
        if (menuFileUrl != null) 'menuFileUrl': menuFileUrl,
      };
}

/// Only "The Garden" is seeded — it's the one food-serving spot already
/// verified in `poi_config.dart`. A real "Pool Cafe" or similar can be
/// added for real from the Admin Panel once its details are confirmed,
/// rather than guessed at here.
const campusFoodVenues = <CampusFoodVenue>[
  CampusFoodVenue(id: 'garden', name: 'The Garden'),
];
