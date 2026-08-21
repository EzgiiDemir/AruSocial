import 'package:flutter/widgets.dart';

/// The admin panel's own language — deliberately separate from the main
/// app's [AppLanguage]/[AppStrings] (see `app_strings.dart`): the admin
/// panel is used independently of a student session and only needs
/// Turkish/English, not the app's TR/EN/RU set.
enum AdminLanguage { tr, en }

AdminLanguage adminLanguageFromCode(String code) =>
    code.toUpperCase() == 'EN' ? AdminLanguage.en : AdminLanguage.tr;

/// Small, hand-maintained translation table for the CMS-style admin panel
/// (`AdminPanelScreen` and everything under it): sidebar/top bar chrome,
/// the dashboard, the shared list toolbar, and every add/edit dialog.
/// Same fallback rule as [AppStrings]: a missing key shows the raw key
/// instead of crashing, so gaps are visible rather than silent.
class AdminStrings {
  final AdminLanguage language;
  const AdminStrings(this.language);

  static const Map<String, Map<AdminLanguage, String>> _table = {
    // Sidebar / top bar
    'admin_nav_dashboard': {AdminLanguage.tr: 'Dashboard', AdminLanguage.en: 'Dashboard'},
    'admin_nav_stats': {AdminLanguage.tr: 'İstatistikler', AdminLanguage.en: 'Statistics'},
    'admin_nav_events': {AdminLanguage.tr: 'Etkinlikler', AdminLanguage.en: 'Events'},
    'admin_nav_pending_activities': {AdminLanguage.tr: 'Bekleyen Aktiviteler', AdminLanguage.en: 'Pending Activities'},
    'admin_nav_clubs': {AdminLanguage.tr: 'Kulüpler', AdminLanguage.en: 'Clubs'},
    'admin_nav_sports': {AdminLanguage.tr: 'Spor', AdminLanguage.en: 'Sports'},
    'admin_nav_services': {AdminLanguage.tr: 'Hizmetler', AdminLanguage.en: 'Services'},
    'admin_nav_food': {AdminLanguage.tr: 'Yemek', AdminLanguage.en: 'Food'},
    'admin_nav_directory': {AdminLanguage.tr: 'Bina Dizini', AdminLanguage.en: 'Building Directory'},
    'admin_nav_pages': {AdminLanguage.tr: 'Sayfalar', AdminLanguage.en: 'Pages'},
    'admin_nav_media': {AdminLanguage.tr: 'Medya Kütüphanesi', AdminLanguage.en: 'Media Library'},
    'admin_nav_surveys': {AdminLanguage.tr: 'Anketler', AdminLanguage.en: 'Surveys'},
    'admin_nav_academic_years': {AdminLanguage.tr: 'Akademik Yıllar', AdminLanguage.en: 'Academic Years'},
    'admin_nav_email_log': {AdminLanguage.tr: 'E-posta', AdminLanguage.en: 'Email'},
    'admin_nav_users': {AdminLanguage.tr: 'Kullanıcılar & Roller', AdminLanguage.en: 'Users & Roles'},
    'admin_nav_moderation': {AdminLanguage.tr: 'Moderasyon', AdminLanguage.en: 'Moderation'},
    'admin_nav_activity_log': {AdminLanguage.tr: 'Aktivite Günlüğü', AdminLanguage.en: 'Activity Log'},
    'admin_nav_site_settings': {AdminLanguage.tr: 'Site Ayarları', AdminLanguage.en: 'Site Settings'},
    'admin_section_platforms': {AdminLanguage.tr: 'PLATFORMLAR', AdminLanguage.en: 'PLATFORMS'},
    'admin_section_content': {AdminLanguage.tr: 'İÇERİK', AdminLanguage.en: 'CONTENT'},
    'admin_section_media': {AdminLanguage.tr: 'MEDYA', AdminLanguage.en: 'MEDIA'},
    'admin_section_engagement': {AdminLanguage.tr: 'ETKİLEŞİM', AdminLanguage.en: 'ENGAGEMENT'},
    'admin_section_system': {AdminLanguage.tr: 'SİSTEM', AdminLanguage.en: 'SYSTEM'},
    'admin_logout': {AdminLanguage.tr: 'Çıkış Yap', AdminLanguage.en: 'Log Out'},

    // Dashboard
    'admin_dash_quick_actions': {AdminLanguage.tr: 'Hızlı İşlemler', AdminLanguage.en: 'Quick Actions'},
    'admin_dash_new_event': {AdminLanguage.tr: 'Yeni Etkinlik', AdminLanguage.en: 'New Event'},
    'admin_dash_new_club': {AdminLanguage.tr: 'Yeni Kulüp', AdminLanguage.en: 'New Club'},
    'admin_dash_enter_menu': {AdminLanguage.tr: 'Menü Gir', AdminLanguage.en: 'Enter Menu'},
    'admin_dash_open_moderation': {AdminLanguage.tr: 'Moderasyonu Aç', AdminLanguage.en: 'Open Moderation'},
    'admin_dash_overview': {AdminLanguage.tr: 'Genel Bakış', AdminLanguage.en: 'Overview'},
    'admin_dash_stat_events': {AdminLanguage.tr: 'Etkinlikler', AdminLanguage.en: 'Events'},
    'admin_dash_stat_draft_suffix': {AdminLanguage.tr: 'taslak', AdminLanguage.en: 'draft'},
    'admin_dash_stat_clubs': {AdminLanguage.tr: 'Kulüpler', AdminLanguage.en: 'Clubs'},
    'admin_dash_stat_sports': {AdminLanguage.tr: 'Spor', AdminLanguage.en: 'Sports'},
    'admin_dash_stat_services': {AdminLanguage.tr: 'Hizmetler', AdminLanguage.en: 'Services'},
    'admin_dash_stat_food_venues': {AdminLanguage.tr: 'Yemek Noktaları', AdminLanguage.en: 'Food Venues'},
    'admin_dash_stat_directory': {AdminLanguage.tr: 'Bina Dizini Kaydı', AdminLanguage.en: 'Directory Entries'},
    'admin_dash_stat_media': {AdminLanguage.tr: 'Medya', AdminLanguage.en: 'Media'},
    'admin_dash_stat_pending_moderation': {AdminLanguage.tr: 'Bekleyen Moderasyon', AdminLanguage.en: 'Pending Reports'},
    'admin_dash_recent_activity': {AdminLanguage.tr: 'Son Aktiviteler', AdminLanguage.en: 'Recent Activity'},
    'admin_dash_view_all': {AdminLanguage.tr: 'Tümü', AdminLanguage.en: 'View All'},
    'admin_dash_local_note': {
      AdminLanguage.tr:
          'Not: bu sayılar bu cihazda kayıtlı gerçek veriden geliyor — henüz başka bir cihaz/admin ile senkron değil.',
      AdminLanguage.en:
          'Note: these numbers come from real data stored on this device — not yet synced with other devices/admins.',
    },

    // İstatistikler tab
    'admin_stats_checkins': {AdminLanguage.tr: 'Check-in\'ler', AdminLanguage.en: 'Check-ins'},
    'admin_stats_total_checkins': {AdminLanguage.tr: 'Toplam Check-in', AdminLanguage.en: 'Total Check-ins'},
    'admin_stats_visible_checkins': {
      AdminLanguage.tr: 'Paylaşılan Check-in', AdminLanguage.en: 'Shared Check-ins',
    },
    'admin_stats_total_xp': {AdminLanguage.tr: 'Toplam XP', AdminLanguage.en: 'Total XP'},
    'admin_stats_banned_accounts': {AdminLanguage.tr: 'Yasaklı Hesap', AdminLanguage.en: 'Banned Accounts'},
    'admin_stats_most_checked_in_places': {
      AdminLanguage.tr: 'En çok check-in yapılan yerler',
      AdminLanguage.en: 'Most checked-in places',
    },
    'admin_stats_no_data_yet': {AdminLanguage.tr: 'Henüz veri yok.', AdminLanguage.en: 'No data yet.'},
    'admin_stats_checkins_by_day': {
      AdminLanguage.tr: 'Günlük check-in trendi', AdminLanguage.en: 'Daily check-in trend',
    },
    'admin_stats_events': {AdminLanguage.tr: 'Etkinlikler', AdminLanguage.en: 'Events'},
    'admin_stats_total_joins': {AdminLanguage.tr: 'Toplam Katılım', AdminLanguage.en: 'Total Joins'},
    'admin_stats_forms_submitted': {
      AdminLanguage.tr: 'Form Tamamlandı', AdminLanguage.en: 'Forms Completed',
    },
    'admin_stats_attendance_approved': {
      AdminLanguage.tr: 'Yoklama Onaylandı', AdminLanguage.en: 'Attendance Approved',
    },
    'admin_stats_pending_review_events': {
      AdminLanguage.tr: 'İncelemede Aktivite', AdminLanguage.en: 'Activities Pending Review',
    },
    'admin_stats_most_joined_events': {
      AdminLanguage.tr: 'En çok katılım alan etkinlikler',
      AdminLanguage.en: 'Most joined events',
    },
    'admin_stats_social': {AdminLanguage.tr: 'Sosyal', AdminLanguage.en: 'Social'},
    'admin_stats_feed_posts': {AdminLanguage.tr: 'Akış Gönderisi', AdminLanguage.en: 'Feed Posts'},
    'admin_stats_comments': {AdminLanguage.tr: 'Yorum', AdminLanguage.en: 'Comments'},
    'admin_stats_stories': {AdminLanguage.tr: 'Hikaye', AdminLanguage.en: 'Stories'},
    'admin_stats_reviews': {AdminLanguage.tr: 'Değerlendirme', AdminLanguage.en: 'Reviews'},
    'admin_stats_average_suffix': {AdminLanguage.tr: 'ortalama', AdminLanguage.en: 'average'},
    'admin_stats_reports_filed': {AdminLanguage.tr: 'Şikayet Sayısı', AdminLanguage.en: 'Reports Filed'},
    'admin_stats_unresolved_suffix': {
      AdminLanguage.tr: 'çözülmedi', AdminLanguage.en: 'unresolved',
    },
    'admin_stats_activity_by_kind': {
      AdminLanguage.tr: 'Türe göre etkinlik', AdminLanguage.en: 'Activity by kind',
    },
    'admin_stats_kind_checkin': {AdminLanguage.tr: 'Check-in', AdminLanguage.en: 'Check-in'},
    'admin_stats_kind_event_join': {
      AdminLanguage.tr: 'Etkinlik Katılımı', AdminLanguage.en: 'Event Join',
    },
    'admin_stats_kind_review': {AdminLanguage.tr: 'Değerlendirme', AdminLanguage.en: 'Review'},
    'admin_stats_kind_comment': {AdminLanguage.tr: 'Yorum', AdminLanguage.en: 'Comment'},
    'admin_stats_kind_like': {AdminLanguage.tr: 'Beğeni', AdminLanguage.en: 'Like'},
    'admin_stats_kind_report': {AdminLanguage.tr: 'Şikayet', AdminLanguage.en: 'Report'},
    'admin_stats_surveys_email': {
      AdminLanguage.tr: 'Anketler ve E-posta', AdminLanguage.en: 'Surveys & Email',
    },
    'admin_stats_total_surveys': {AdminLanguage.tr: 'Toplam Anket', AdminLanguage.en: 'Total Surveys'},
    'admin_stats_survey_responses': {
      AdminLanguage.tr: 'Anket Yanıtı', AdminLanguage.en: 'Survey Responses',
    },
    'admin_stats_emails_sent': {AdminLanguage.tr: 'Gönderilen E-posta', AdminLanguage.en: 'Emails Sent'},
    'admin_stats_emails_failed': {
      AdminLanguage.tr: 'Başarısız E-posta', AdminLanguage.en: 'Failed Emails',
    },
    'admin_stats_catalog': {AdminLanguage.tr: 'İçerik Kataloğu', AdminLanguage.en: 'Content Catalog'},
    'admin_stats_places': {AdminLanguage.tr: 'Mekân', AdminLanguage.en: 'Places'},

    // Site Settings tab — check-in radius
    'admin_checkin_radius_title': {
      AdminLanguage.tr: 'Check-in Mesafe Eşiği', AdminLanguage.en: 'Check-in Distance Threshold',
    },
    'admin_checkin_radius_desc': {
      AdminLanguage.tr: 'Öğrenci bir mekâna check-in yapabilmek için gerçek GPS konumuyla bu mesafenin '
          'içinde olmalı — backend bunu her check-in isteğinde gerçekten hesaplıyor, sadece istemcinin '
          'söylediğine güvenmiyor.',
      AdminLanguage.en: 'A student must be within this real GPS distance of a place to check in — the '
          'backend genuinely recomputes this on every request, it never trusts what the client claims.',
    },

    // Shared list toolbar
    'admin_toolbar_selected_suffix': {AdminLanguage.tr: 'seçildi', AdminLanguage.en: 'selected'},
    'admin_toolbar_delete': {AdminLanguage.tr: 'Sil', AdminLanguage.en: 'Delete'},
    'admin_search_events': {AdminLanguage.tr: 'Etkinlik ara...', AdminLanguage.en: 'Search events...'},
    'admin_search_clubs': {AdminLanguage.tr: 'Kulüp ara...', AdminLanguage.en: 'Search clubs...'},
    'admin_search_sports': {AdminLanguage.tr: 'Spor ara...', AdminLanguage.en: 'Search sports...'},
    'admin_search_services': {AdminLanguage.tr: 'Hizmet ara...', AdminLanguage.en: 'Search services...'},
    'admin_search_food': {AdminLanguage.tr: 'Yemek noktası ara...', AdminLanguage.en: 'Search food venues...'},
    'admin_search_directory': {AdminLanguage.tr: 'İsim ara...', AdminLanguage.en: 'Search by name...'},

    // Shared dialog chrome / fields
    'admin_cancel': {AdminLanguage.tr: 'Vazgeç', AdminLanguage.en: 'Cancel'},
    'admin_save': {AdminLanguage.tr: 'Kaydet', AdminLanguage.en: 'Save'},
    'admin_history': {AdminLanguage.tr: 'Geçmiş', AdminLanguage.en: 'History'},
    'admin_edit_content': {AdminLanguage.tr: 'İçerik Düzenle', AdminLanguage.en: 'Edit Content'},
    'admin_blocks_suffix': {AdminLanguage.tr: 'blok', AdminLanguage.en: 'blocks'},
    'admin_none': {AdminLanguage.tr: 'Yok', AdminLanguage.en: 'None'},
    'admin_field_title': {AdminLanguage.tr: 'Başlık', AdminLanguage.en: 'Title'},
    'admin_field_name': {AdminLanguage.tr: 'Ad', AdminLanguage.en: 'Name'},
    'admin_field_category': {AdminLanguage.tr: 'Kategori', AdminLanguage.en: 'Category'},
    'admin_field_email': {AdminLanguage.tr: 'E-posta', AdminLanguage.en: 'Email'},
    'admin_field_building': {AdminLanguage.tr: 'Bina', AdminLanguage.en: 'Building'},
    'admin_field_floor': {AdminLanguage.tr: 'Kat', AdminLanguage.en: 'Floor'},
    'admin_field_room': {AdminLanguage.tr: 'Oda', AdminLanguage.en: 'Room'},
    'admin_field_description_short': {AdminLanguage.tr: 'Açıklama (kısa özet)', AdminLanguage.en: 'Description (short summary)'},
    'admin_section_basic_info': {AdminLanguage.tr: 'Temel Bilgiler', AdminLanguage.en: 'Basic Info'},
    'admin_section_location': {AdminLanguage.tr: 'Konum', AdminLanguage.en: 'Location'},
    'admin_section_location_optional': {AdminLanguage.tr: 'Konum (opsiyonel)', AdminLanguage.en: 'Location (optional)'},
    'admin_section_occupant': {AdminLanguage.tr: 'Kişi / Birim', AdminLanguage.en: 'Occupant'},

    // Events dialog
    'admin_event_new': {AdminLanguage.tr: 'Yeni Etkinlik', AdminLanguage.en: 'Add New Event'},
    'admin_event_edit': {AdminLanguage.tr: 'Etkinliği Düzenle', AdminLanguage.en: 'Edit Event'},
    'admin_event_time': {AdminLanguage.tr: 'Saat (14:00)', AdminLanguage.en: 'Time (14:00)'},
    'admin_field_place': {AdminLanguage.tr: 'Yer', AdminLanguage.en: 'Location'},
    'admin_event_organizer': {AdminLanguage.tr: 'Organizatör', AdminLanguage.en: 'Organizer'},
    'admin_event_content_title': {AdminLanguage.tr: 'Etkinlik İçeriği', AdminLanguage.en: 'Event Content'},
    'admin_event_attendees': {AdminLanguage.tr: 'Katılımcı sayısı', AdminLanguage.en: 'Attendee Count'},
    'admin_event_xp': {AdminLanguage.tr: 'XP ödülü', AdminLanguage.en: 'XP Reward'},
    'admin_section_publishing': {AdminLanguage.tr: 'Yayın ve Hedef Kitle', AdminLanguage.en: 'Publishing & Audience'},
    'admin_event_audience': {AdminLanguage.tr: 'Hedef Kitle', AdminLanguage.en: 'Audience'},
    'admin_event_audience_hint': {AdminLanguage.tr: 'Tümü, Mimarlık Fakültesi, …', AdminLanguage.en: 'All, Faculty of Architecture, …'},
    'admin_event_publish_date': {
      AdminLanguage.tr: 'Yayın Tarihi (YYYY-MM-DD, boş = hemen)',
      AdminLanguage.en: 'Publish Date (YYYY-MM-DD, blank = immediately)',
    },
    'admin_event_expiry_date': {
      AdminLanguage.tr: 'Son Görünme Tarihi (YYYY-MM-DD, boş = süresiz)',
      AdminLanguage.en: 'Expiry Date (YYYY-MM-DD, blank = never)',
    },
    'admin_event_draft_switch': {AdminLanguage.tr: 'Taslak (öğrencilere gösterme)', AdminLanguage.en: 'Draft (hide from students)'},
    'admin_event_registered_place': {
      AdminLanguage.tr: 'Kayıtlı mekân (opsiyonel, seçilince Yer alanını doldurur)',
      AdminLanguage.en: 'Registered place (optional, fills the Location field)',
    },
    'admin_event_organizer_email': {AdminLanguage.tr: 'Organizatör E-postası', AdminLanguage.en: 'Organizer Email'},
    'admin_event_organizer_email_hint': {
      AdminLanguage.tr: 'Katılım talebi bildirimleri buraya gider',
      AdminLanguage.en: 'Join-request notifications go here',
    },
    'admin_event_academic_year': {AdminLanguage.tr: 'Akademik Yıl', AdminLanguage.en: 'Academic Year'},
    'admin_event_participation_types': {AdminLanguage.tr: 'Katılım Türleri', AdminLanguage.en: 'Participation Types'},
    'admin_event_participation_types_empty': {
      AdminLanguage.tr: 'Henüz katılım türü yok — öğrenci "Katıl" dediğinde direkt onaylanır.',
      AdminLanguage.en: 'No participation types yet — students confirm directly on "Join".',
    },
    'admin_event_participation_type_hint': {AdminLanguage.tr: 'Örn. Gönüllü', AdminLanguage.en: 'E.g. Volunteer'},
    'admin_event_attendance': {AdminLanguage.tr: 'Yoklama', AdminLanguage.en: 'Attendance'},
    'admin_event_attendance_empty': {
      AdminLanguage.tr: 'Henüz katılan olmadı.',
      AdminLanguage.en: 'No one has joined yet.',
    },
    'admin_event_attendance_approve': {AdminLanguage.tr: 'Onayla', AdminLanguage.en: 'Approve'},
    'admin_event_attendance_form_pending': {
      AdminLanguage.tr: 'Form bekleniyor',
      AdminLanguage.en: 'Form pending',
    },
    'admin_close': {AdminLanguage.tr: 'Kapat', AdminLanguage.en: 'Close'},

    // Clubs dialog
    'admin_club_new': {AdminLanguage.tr: 'Yeni Kulüp', AdminLanguage.en: 'Add New Club'},
    'admin_club_edit': {AdminLanguage.tr: 'Kulübü Düzenle', AdminLanguage.en: 'Edit Club'},
    'admin_club_name': {AdminLanguage.tr: 'Kulüp adı', AdminLanguage.en: 'Club Name'},
    'admin_club_content_title': {AdminLanguage.tr: 'Kulüp İçeriği', AdminLanguage.en: 'Club Content'},

    // Sports dialog
    'admin_sport_new': {AdminLanguage.tr: 'Yeni Spor', AdminLanguage.en: 'Add New Sport'},
    'admin_sport_edit': {AdminLanguage.tr: 'Sporu Düzenle', AdminLanguage.en: 'Edit Sport'},
    'admin_sport_facility': {AdminLanguage.tr: 'Tesis', AdminLanguage.en: 'Facility'},
    'admin_sport_contact_email': {
      AdminLanguage.tr: 'Katılım E-postası (boş bırakılırsa genel destek kullanılır)',
      AdminLanguage.en: 'Signup Email (leave blank to use general support)',
    },

    // Services dialog
    'admin_service_new': {AdminLanguage.tr: 'Yeni Hizmet', AdminLanguage.en: 'Add New Service'},
    'admin_service_edit': {AdminLanguage.tr: 'Hizmeti Düzenle', AdminLanguage.en: 'Edit Service'},
    'admin_service_content_title': {AdminLanguage.tr: 'Hizmet İçeriği', AdminLanguage.en: 'Service Content'},
    'admin_service_topics': {AdminLanguage.tr: 'Konu başlıkları (virgülle ayır)', AdminLanguage.en: 'Topics (comma-separated)'},
    'admin_service_topics_hint': {AdminLanguage.tr: 'Ders Kaydı, Öğrenci Kimlik Kartı, …', AdminLanguage.en: 'Course Registration, Student ID Card, …'},
    'admin_field_hours': {AdminLanguage.tr: 'Çalışma Saatleri', AdminLanguage.en: 'Business Hours'},
    'admin_hours_hint': {AdminLanguage.tr: 'Hafta içi 09:00–17:00', AdminLanguage.en: 'Weekdays 9:00 AM–5:00 PM'},
    'admin_service_contact_person': {AdminLanguage.tr: 'Yetkili kişi', AdminLanguage.en: 'Contact Person'},

    // Food venue dialog
    'admin_venue_new': {AdminLanguage.tr: 'Yeni Yemek Noktası', AdminLanguage.en: 'Add New Food Venue'},
    'admin_venue_edit': {AdminLanguage.tr: 'Yemek Noktasını Düzenle', AdminLanguage.en: 'Edit Food Venue'},
    'admin_venue_hours': {AdminLanguage.tr: 'Genel Çalışma Saatleri', AdminLanguage.en: 'General Business Hours'},
    'admin_venue_hours_hint': {AdminLanguage.tr: 'Hafta içi 08:00–17:00', AdminLanguage.en: 'Weekdays 8:00 AM–5:00 PM'},
    'admin_venue_menu_file': {AdminLanguage.tr: 'Aylık Menü Dosyası Linki (opsiyonel)', AdminLanguage.en: 'Monthly Menu File Link (optional)'},
    'admin_venue_menu_file_hint': {AdminLanguage.tr: 'https://... .pdf', AdminLanguage.en: 'https://... .pdf'},
    'admin_venue_note': {
      AdminLanguage.tr: 'Günlük menüleri (tarih, ürünler, ücret) kaydettikten sonra listedeki takvim ikonundan girebilirsin.',
      AdminLanguage.en: 'Once this is saved, enter daily menus (date, items, price) from the calendar icon in the list.',
    },

    // Daily menu dialog
    'admin_day_title': {AdminLanguage.tr: 'Günlük Menü', AdminLanguage.en: 'Daily Menu'},
    'admin_day_pick_date': {AdminLanguage.tr: 'Tarih Seç', AdminLanguage.en: 'Pick Date'},
    'admin_day_items': {AdminLanguage.tr: 'Yemekler (virgülle ayır)', AdminLanguage.en: 'Items (comma-separated)'},
    'admin_day_items_hint': {AdminLanguage.tr: 'Mercimek çorbası, Izgara tavuk, …', AdminLanguage.en: 'Lentil Soup, Grilled Chicken, …'},
    'admin_day_price': {AdminLanguage.tr: 'Ücret', AdminLanguage.en: 'Price'},
    'admin_day_price_hint': {AdminLanguage.tr: '₺150', AdminLanguage.en: '₺150'},
    'admin_day_hours': {AdminLanguage.tr: 'O günün saatleri (opsiyonel)', AdminLanguage.en: "This day's hours (optional)"},
    'admin_day_hours_hint': {AdminLanguage.tr: '11:30–14:00', AdminLanguage.en: '11:30 AM–2:00 PM'},
    'admin_day_calendar_title': {AdminLanguage.tr: 'Menü Takvimi', AdminLanguage.en: 'Menu Calendar'},
    'admin_day_empty': {
      AdminLanguage.tr: 'Henüz günlük menü girilmedi. + ile ilk günü ekle.',
      AdminLanguage.en: 'No daily menus yet. Tap + to add the first day.',
    },

    // Directory entry dialog
    'admin_dir_new': {AdminLanguage.tr: 'Yeni Dizin Kaydı', AdminLanguage.en: 'Add New Directory Entry'},
    'admin_dir_edit': {AdminLanguage.tr: 'Kaydı Düzenle', AdminLanguage.en: 'Edit Entry'},
    'admin_dir_occupant_name': {AdminLanguage.tr: 'Kişi / Birim Adı', AdminLanguage.en: 'Person / Unit Name'},
    'admin_dir_occupant_role': {AdminLanguage.tr: 'Görev / Departman', AdminLanguage.en: 'Title / Department'},
    'admin_dir_related_service': {AdminLanguage.tr: 'İlgili Hizmet (opsiyonel)', AdminLanguage.en: 'Related Service (optional)'},

    // Pages dialog
    'admin_page_new': {AdminLanguage.tr: 'Yeni Sayfa', AdminLanguage.en: 'Add New Page'},
    'admin_page_edit': {AdminLanguage.tr: 'Sayfayı Düzenle', AdminLanguage.en: 'Edit Page'},
    'admin_page_slug': {AdminLanguage.tr: 'Slug', AdminLanguage.en: 'Slug'},
    'admin_page_content_title': {AdminLanguage.tr: 'Sayfa İçeriği', AdminLanguage.en: 'Page Content'},
    'admin_page_publish_switch': {AdminLanguage.tr: 'Yayınla (öğrenciler görsün)', AdminLanguage.en: 'Publish (visible to students)'},

    // Role assignment dialog
    'admin_role_new': {AdminLanguage.tr: 'Rol Ata', AdminLanguage.en: 'Assign Role'},
    'admin_role_edit': {AdminLanguage.tr: 'Rolü Düzenle', AdminLanguage.en: 'Edit Role'},
    'admin_role_email': {AdminLanguage.tr: 'E-posta (@arucad.edu.tr)', AdminLanguage.en: 'Email (@arucad.edu.tr)'},
    'admin_field_role': {AdminLanguage.tr: 'Rol', AdminLanguage.en: 'Role'},

    // Misc chrome scattered across list tabs
    'admin_empty_events': {AdminLanguage.tr: 'Henüz etkinlik yok', AdminLanguage.en: 'No events yet'},
    'admin_role_local_note': {
      AdminLanguage.tr: 'Bu atamalar gerçek kaydediliyor, ama henüz hiçbir API isteğini bu role '
          'göre reddetmiyor — gerçek Entra grup/rol eşlemesi ve yetki zorlaması henüz yok '
          '(bkz. docs/EKSIKLER.md §1, §11). ezgi.demir@arucad.edu.tr her zaman Yönetici olarak kalır.',
      AdminLanguage.en: 'Assignments here are saved for real, but no API request is rejected based on '
          'role yet — real Entra group/role mapping and access enforcement don\'t exist yet '
          '(see docs/EKSIKLER.md §1, §11). ezgi.demir@arucad.edu.tr always stays Admin.'
    },
    'admin_role_none_yet': {AdminLanguage.tr: 'Henüz özel rol ataması yok.', AdminLanguage.en: 'No custom role assignments yet.'},
    'admin_role_assigned_by': {AdminLanguage.tr: '{role} · {name} atadı', AdminLanguage.en: '{role} · assigned by {name}'},
    'admin_content_remove': {AdminLanguage.tr: 'İçeriği Kaldır', AdminLanguage.en: 'Remove Content'},
    'admin_no_pending_reports': {AdminLanguage.tr: 'Bekleyen şikayet yok', AdminLanguage.en: 'No pending reports'},
    'admin_saving_ellipsis': {AdminLanguage.tr: 'Kaydediliyor…', AdminLanguage.en: 'Saving…'},
    'admin_no_activity_yet': {AdminLanguage.tr: 'Henüz kayıtlı bir işlem yok.', AdminLanguage.en: 'No recorded actions yet.'},
    'admin_record_noun': {AdminLanguage.tr: 'kayıt', AdminLanguage.en: 'entry'},
    'admin_reason_prefix': {AdminLanguage.tr: 'Sebep', AdminLanguage.en: 'Reason'},
    'admin_action_dismiss': {AdminLanguage.tr: 'Reddet', AdminLanguage.en: 'Dismiss'},
    'admin_action_warn': {AdminLanguage.tr: 'Uyar', AdminLanguage.en: 'Warn'},
    'admin_action_dismissed': {AdminLanguage.tr: 'Reddedildi', AdminLanguage.en: 'Dismissed'},
    'admin_action_warned': {AdminLanguage.tr: 'Uyarıldı', AdminLanguage.en: 'Warned'},
    'admin_action_removed': {AdminLanguage.tr: 'Kaldırıldı', AdminLanguage.en: 'Removed'},
    'admin_status_draft': {AdminLanguage.tr: 'Taslak', AdminLanguage.en: 'Draft'},
    'admin_status_scheduled': {AdminLanguage.tr: 'Zamanlandı', AdminLanguage.en: 'Scheduled'},
    'admin_status_expired': {AdminLanguage.tr: 'Süresi doldu', AdminLanguage.en: 'Expired'},
    'admin_status_published': {AdminLanguage.tr: 'Yayında', AdminLanguage.en: 'Published'},
    'admin_food_daily_menu_empty': {AdminLanguage.tr: 'Günlük menü henüz girilmedi', AdminLanguage.en: 'No daily menu entered yet'},
    'admin_food_daily_menu_count': {AdminLanguage.tr: 'günlük menü kayıtlı', AdminLanguage.en: 'daily menus recorded'},
    'admin_food_calendar_tooltip': {AdminLanguage.tr: 'Günlük menü takvimi', AdminLanguage.en: 'Daily menu calendar'},
    'admin_directory_empty': {
      AdminLanguage.tr: 'Henüz bina dizini girilmedi. Gerçek oda/kişi bilgisi eklendikçe burada '
          've ilgili hizmetin detay sayfasında görünecek.',
      AdminLanguage.en: 'No directory entries yet. Once real room/person data is added, it will '
          'appear here and on the related service\'s detail page.'
    },
    'admin_pages_empty': {
      AdminLanguage.tr: 'Henüz sayfa yok. Örn. "Hakkımızda", "SSS", "Gizlilik Politikası" gibi '
          'sayfalar için + ile başla.',
      AdminLanguage.en: 'No pages yet. Tap + to start pages like "About Us", "FAQ", or "Privacy Policy".'
    },

    // Site Settings tab — Entra
    'admin_entra_title': {AdminLanguage.tr: 'Microsoft Entra Bilgilerini Güncelle', AdminLanguage.en: 'Update Microsoft Entra Settings'},
    'admin_entra_desc': {
      AdminLanguage.tr: 'Gerçek bir Azure App Registration\'dan alınan Tenant ID ve Client ID buraya girildiğinde '
          'uygulama gerçekten Microsoft ile oturum açmayı dener — Client Secret kasıtlı olarak '
          'istenmiyor: mobil (native) OAuth PKCE kullanır, secret gerektirmez ve bir mobil '
          'uygulamanın içine hiçbir zaman güvenle gömülemez.',
      AdminLanguage.en: 'Entering a real Tenant ID and Client ID from an Azure App Registration makes the '
          'app genuinely attempt Microsoft sign-in — a Client Secret is deliberately not asked for: '
          'mobile (native) OAuth uses PKCE, needs no secret, and a secret can never be safely embedded '
          'inside a mobile app anyway.'
    },
    'admin_entra_tenant_id': {AdminLanguage.tr: 'Tenant ID', AdminLanguage.en: 'Tenant ID'},
    'admin_entra_client_id': {AdminLanguage.tr: 'Client ID', AdminLanguage.en: 'Client ID'},
    'admin_entra_redirect_uri': {AdminLanguage.tr: 'Redirect URI', AdminLanguage.en: 'Redirect URI'},
    'admin_entra_redirect_note': {
      AdminLanguage.tr: 'Not: Redirect URI\'nin şeması (com.example.arucad_campus_prototype://…) Android tarafında '
          'derleme anında sabitlenir (android/app/build.gradle.kts → appAuthRedirectScheme). Farklı bir '
          'şema kullanmak istersen o dosyayı da güncelleyip uygulamayı yeniden derlemen gerekir; bu alanı '
          'değiştirmek tek başına yeterli olmaz.',
      AdminLanguage.en: 'Note: the Redirect URI\'s scheme (com.example.arucad_campus_prototype://…) is fixed at '
          'build time on Android (android/app/build.gradle.kts → appAuthRedirectScheme). Using a different '
          'scheme means updating that file too and rebuilding the app; changing this field alone isn\'t enough.'
    },
    'admin_entra_save': {AdminLanguage.tr: 'Entra Bilgilerini Kaydet', AdminLanguage.en: 'Save Entra Settings'},
    'admin_entra_saved_toast': {
      AdminLanguage.tr: 'Kaydedildi. Uygulamayı kapatıp yeniden açtığında (main.dart bunu başlangıçta okur) Microsoft ile giriş seçeneği aktif olur.',
      AdminLanguage.en: 'Saved. The Microsoft sign-in option activates the next time the app is closed and reopened (main.dart reads this at startup).'
    },

    // Site Settings tab — WordPress
    'admin_wp_title': {AdminLanguage.tr: 'WordPress / WPForms Veri Kaynağı', AdminLanguage.en: 'WordPress / WPForms Data Source'},
    'admin_wp_desc': {
      AdminLanguage.tr: 'Bu ekran verdiğin site adresine ve token\'a gerçekten HTTP isteği atar '
          '(varsayılan uç nokta: {site}/wp-json/wpforms/v1/forms ve /entries — bunun için WPForms REST '
          'API eklentisi ya da eşdeğer bir uç nokta gerekir). ARUCAD\'in gerçek uç noktası farklıysa '
          'lib/core/services/wordpress_data_source.dart içindeki iki yol sabitini güncellemen yeterli.',
      AdminLanguage.en: 'This screen makes a real HTTP request to the site address and token you provide '
          '(default endpoint: {site}/wp-json/wpforms/v1/forms and /entries — this needs the WPForms REST '
          'API plugin or an equivalent endpoint). If ARUCAD\'s real endpoint differs, update the two path '
          'constants in lib/core/services/wordpress_data_source.dart.'
    },
    'admin_wp_site_url': {AdminLanguage.tr: 'Kaynak Site URL', AdminLanguage.en: 'Source Site URL'},
    'admin_wp_api_token': {AdminLanguage.tr: 'API Token', AdminLanguage.en: 'API Token'},
    'admin_wp_fetch_forms': {AdminLanguage.tr: 'WordPress Formlarını Çek', AdminLanguage.en: 'Fetch WordPress Forms'},
    'admin_wp_fetch_entries': {AdminLanguage.tr: 'Yeni Kayıtları Çek', AdminLanguage.en: 'Fetch New Entries'},
    'admin_wp_entry_noun': {AdminLanguage.tr: 'kayıt', AdminLanguage.en: 'entry'},
    'admin_wp_form_noun': {AdminLanguage.tr: 'form', AdminLanguage.en: 'form'},
    'admin_wp_fetched_suffix': {AdminLanguage.tr: 'çekildi.', AdminLanguage.en: 'fetched.'},
    'admin_wp_connection_failed': {AdminLanguage.tr: 'Bağlantı başarısız', AdminLanguage.en: 'Connection failed'},

    // Site Settings tab — moderation
    'admin_moderation_title': {AdminLanguage.tr: 'İçerik Moderasyonu (Görsel)', AdminLanguage.en: 'Content Moderation (Image)'},
    'admin_moderation_desc': {
      AdminLanguage.tr: 'Bu anahtar yalnızca sunucuda saklanır, hiçbir zaman cihaza/istemciye gönderilmez. '
          'Girersen, bundan sonra yüklenen her fotoğraf backend üzerinden gerçekten omni-moderation-latest '
          'ile taranır: uygunsuz bulunursa paylaşım reddedilir ve hesaba gerçek bir ihlal (strike) işlenir — '
          'metin ihlalleriyle aynı 3-ihlal yasak sayacına dahil. API çağrısının kendisi başarısız olursa '
          '(ağ/anahtar sorunu) paylaşım engellenmez — bir moderasyon kesintisi bütün öğrencilerin fotoğraf '
          'paylaşmasını durduramamalı.',
      AdminLanguage.en: 'This key is stored server-side only and never sent to the device/client. Entering one '
          'means every photo uploaded from now on is genuinely scanned server-side with omni-moderation-latest: '
          'a flagged upload gets rejected and the account takes a real strike — counted toward the same 3-strike '
          'ban threshold as text violations. If the API call itself fails (network/key issue), the upload isn\'t '
          'blocked — a moderation outage shouldn\'t stop every student from posting photos.'
    },
    'admin_moderation_key_label': {
      AdminLanguage.tr: 'Yeni Moderation API Key (boş bırakırsan değişmez)',
      AdminLanguage.en: 'New Moderation API Key (leave blank to keep unchanged)',
    },
    'admin_moderation_save': {AdminLanguage.tr: 'Moderasyon Ayarını Kaydet', AdminLanguage.en: 'Save Moderation Setting'},
    'admin_moderation_clear': {AdminLanguage.tr: 'Anahtarı Kaldır', AdminLanguage.en: 'Remove Key'},
    'admin_moderation_configured': {
      AdminLanguage.tr: 'Sunucuda bir API anahtarı yapılandırılmış',
      AdminLanguage.en: 'A server-side API key is configured',
    },
    'admin_moderation_not_configured': {
      AdminLanguage.tr: 'Henüz yapılandırılmadı — fotoğraflar taranmıyor',
      AdminLanguage.en: 'Not configured yet — photos aren\'t being scanned',
    },
    'admin_moderation_saved_toast': {
      AdminLanguage.tr: 'Kaydedildi. Bundan sonra yüklenen post/story fotoğrafları gerçekten bu API ile taranacak.',
      AdminLanguage.en: 'Saved. Post/story photos uploaded from now on will genuinely be scanned with this API.'
    },
  };

  String t(String key) => _table[key]?[language] ?? key;
}

class AdminLocale extends InheritedWidget {
  final AdminLanguage language;
  final AdminStrings strings;

  AdminLocale({super.key, required this.language, required super.child})
      : strings = AdminStrings(language);

  static AdminStrings of(BuildContext context) {
    final scope = context.dependOnInheritedWidgetOfExactType<AdminLocale>();
    return scope?.strings ?? const AdminStrings(AdminLanguage.tr);
  }

  @override
  bool updateShouldNotify(AdminLocale oldWidget) => oldWidget.language != language;
}
