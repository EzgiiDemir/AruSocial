import 'package:flutter/widgets.dart';

import 'package:arucad_campus_prototype/core/models/campus_models.dart';

enum AppLanguage { tr, en, ru }

AppLanguage languageFromCode(String code) => switch (code) {
      'EN' => AppLanguage.en,
      'RU' => AppLanguage.ru,
      _ => AppLanguage.tr,
    };

/// Small, hand-maintained translation table for the app's chrome — nav
/// labels, screen titles, section headers, buttons, common empty states.
/// It deliberately does NOT cover generated/seeded content (mock reviews,
/// Galatea's answers, event descriptions): translating fabricated demo data
/// three ways would be busywork, not real localization. Real backend content
/// would carry its own locale field.
class AppStrings {
  final AppLanguage language;
  const AppStrings(this.language);

  static const Map<String, Map<AppLanguage, String>> _table = {
    // Nav
    'nav_home': {AppLanguage.tr: 'Ana Sayfa', AppLanguage.en: 'Home', AppLanguage.ru: 'Главная'},
    'nav_explore': {AppLanguage.tr: 'Keşfet', AppLanguage.en: 'Discover', AppLanguage.ru: 'Обзор'},
    'nav_social': {AppLanguage.tr: 'Sosyal', AppLanguage.en: 'Social', AppLanguage.ru: 'Соцсеть'},
    'nav_profile': {AppLanguage.tr: 'Ayarlar', AppLanguage.en: 'Settings', AppLanguage.ru: 'Настройки'},
    'nav_ask': {AppLanguage.tr: "Arucad'a Sor", AppLanguage.en: 'Ask ARUCAD', AppLanguage.ru: 'Спросить ARUCAD'},
    // Shorter form of nav_ask for the bottom nav on narrow phones, where the
    // full label would wrap and force the bar taller than the other items.
    'nav_ask_short': {AppLanguage.tr: 'Sor', AppLanguage.en: 'Ask', AppLanguage.ru: 'ARUCAD'},

    // Home — timeline
    'home_nearby': {AppLanguage.tr: 'Yakınında', AppLanguage.en: 'Near You', AppLanguage.ru: 'Рядом с тобой'},
    'home_nearby_empty': {AppLanguage.tr: 'Şu an yakınında planlı bir şey yok', AppLanguage.en: 'Nothing nearby right now', AppLanguage.ru: 'Сейчас рядом ничего нет'},
    'home_campus_pulse': {AppLanguage.tr: 'Kampüs Nabzı', AppLanguage.en: 'Campus Pulse', AppLanguage.ru: 'Пульс кампуса'},
    'home_open_map': {AppLanguage.tr: 'Haritayı Aç', AppLanguage.en: 'Open Map', AppLanguage.ru: 'Открыть карту'},
    'home_today_events': {AppLanguage.tr: 'Yaklaşan Etkinlikler', AppLanguage.en: 'Upcoming Events', AppLanguage.ru: 'Ближайшие события'},
    'home_all': {AppLanguage.tr: 'Tümü', AppLanguage.en: 'All', AppLanguage.ru: 'Все'},
    'home_no_events': {AppLanguage.tr: 'Yaklaşan etkinlik yok', AppLanguage.en: 'No upcoming events', AppLanguage.ru: 'Нет ближайших событий'},
    'home_for_you': {AppLanguage.tr: 'Sana Özel', AppLanguage.en: 'For You', AppLanguage.ru: 'Для тебя'},
    'home_score': {AppLanguage.tr: 'Aktivite', AppLanguage.en: 'Activity', AppLanguage.ru: 'Активность'},
    'home_go_there': {AppLanguage.tr: 'Oraya Git', AppLanguage.en: 'Go There', AppLanguage.ru: 'Перейти'},
    'home_search_hint': {AppLanguage.tr: 'Yer veya etkinlik ara', AppLanguage.en: 'Search places or events', AppLanguage.ru: 'Поиск мест и событий'},

    // Discover / Help
    'discover_creative': {AppLanguage.tr: 'Etkinlikler', AppLanguage.en: 'Events', AppLanguage.ru: 'События'},
    'discover_bandabuliya': {AppLanguage.tr: 'Bandabuliya Programı', AppLanguage.en: 'Bandabuliya Program', AppLanguage.ru: 'Программа Bandabuliya'},
    'discover_clubs': {AppLanguage.tr: 'Kulüpler', AppLanguage.en: 'Clubs', AppLanguage.ru: 'Клубы'},
    'discover_sports': {AppLanguage.tr: 'Spor', AppLanguage.en: 'Sports', AppLanguage.ru: 'Спорт'},
    'discover_places': {AppLanguage.tr: 'Yerler', AppLanguage.en: 'Places', AppLanguage.ru: 'Места'},
    'discover_services': {AppLanguage.tr: 'Kampüs Hizmetleri', AppLanguage.en: 'Campus Services', AppLanguage.ru: 'Услуги кампуса'},
    'discover_career': {AppLanguage.tr: 'Kariyer', AppLanguage.en: 'Career', AppLanguage.ru: 'Карьера'},
    'discover_food': {AppLanguage.tr: 'Yemek', AppLanguage.en: 'Food', AppLanguage.ru: 'Еда'},
    'discover_calendar': {AppLanguage.tr: 'Takvim', AppLanguage.en: 'Calendar', AppLanguage.ru: 'Календарь'},
    'discover_communities': {AppLanguage.tr: 'Topluluklar', AppLanguage.en: 'Communities', AppLanguage.ru: 'Сообщества'},
    'explore_hub_upcoming': {AppLanguage.tr: 'Yaklaşan', AppLanguage.en: 'Upcoming', AppLanguage.ru: 'Скоро'},
    'explore_hub_upcoming_empty': {AppLanguage.tr: 'Yaklaşan randevu, başvuru veya etkinlik yok', AppLanguage.en: 'No upcoming appointments, applications, or events', AppLanguage.ru: 'Нет ближайших встреч, заявок или событий'},
    'explore_hub_see_calendar': {AppLanguage.tr: 'Takvimi aç', AppLanguage.en: 'Open calendar', AppLanguage.ru: 'Открыть календарь'},
    'explore_cal_filter_all': {AppLanguage.tr: 'Hepsi', AppLanguage.en: 'All', AppLanguage.ru: 'Все'},
    'explore_cal_filter_appointment': {AppLanguage.tr: 'Randevu', AppLanguage.en: 'Appointments', AppLanguage.ru: 'Встречи'},
    'explore_cal_filter_application': {AppLanguage.tr: 'Başvuru', AppLanguage.en: 'Applications', AppLanguage.ru: 'Заявки'},
    'explore_cal_filter_event': {AppLanguage.tr: 'Etkinlik', AppLanguage.en: 'Events', AppLanguage.ru: 'События'},
    'explore_cal_empty_day': {AppLanguage.tr: 'Bu günde kayıt yok', AppLanguage.en: 'Nothing on this day', AppLanguage.ru: 'В этот день записей нет'},
    'explore_cal_empty': {AppLanguage.tr: 'Yaklaşan randevu, başvuru veya etkinlik yok', AppLanguage.en: 'No upcoming appointments, applications, or events', AppLanguage.ru: 'Нет ближайших встреч, заявок или событий'},
    'explore_cal_selected': {AppLanguage.tr: 'Seçili gün', AppLanguage.en: 'Selected day', AppLanguage.ru: 'Выбранный день'},
    'explore_events_search': {AppLanguage.tr: 'Etkinlik ara', AppLanguage.en: 'Search events', AppLanguage.ru: 'Поиск событий'},
    'explore_events_filter': {AppLanguage.tr: 'Filtrele', AppLanguage.en: 'Filter', AppLanguage.ru: 'Фильтр'},
    'explore_events_empty': {AppLanguage.tr: 'Etkinlik bulunamadı', AppLanguage.en: 'No events found', AppLanguage.ru: 'События не найдены'},
    'explore_sports_search': {AppLanguage.tr: 'Spor ara', AppLanguage.en: 'Search sports', AppLanguage.ru: 'Поиск спорта'},
    'explore_sports_filter': {AppLanguage.tr: 'Filtrele', AppLanguage.en: 'Filter', AppLanguage.ru: 'Фильтр'},
    'explore_sports_empty': {AppLanguage.tr: 'Spor bulunamadı', AppLanguage.en: 'No sports found', AppLanguage.ru: 'Спорт не найден'},
    'explore_sports_join': {AppLanguage.tr: 'Katıl', AppLanguage.en: 'Join', AppLanguage.ru: 'Участвовать'},
    'explore_clubs_search': {AppLanguage.tr: 'Kulüp ara', AppLanguage.en: 'Search clubs', AppLanguage.ru: 'Поиск клубов'},
    'explore_clubs_filter': {AppLanguage.tr: 'Filtrele', AppLanguage.en: 'Filter', AppLanguage.ru: 'Фильтр'},
    'explore_clubs_empty': {AppLanguage.tr: 'Kulüp bulunamadı', AppLanguage.en: 'No clubs found', AppLanguage.ru: 'Клубы не найдены'},
    'explore_places_search': {AppLanguage.tr: 'Yer ara', AppLanguage.en: 'Search places', AppLanguage.ru: 'Поиск мест'},
    'explore_places_filter': {AppLanguage.tr: 'Filtrele', AppLanguage.en: 'Filter', AppLanguage.ru: 'Фильтр'},
    'explore_places_empty': {AppLanguage.tr: 'Yer bulunamadı', AppLanguage.en: 'No places found', AppLanguage.ru: 'Места не найдены'},
    'explore_cat_calendar_sub': {AppLanguage.tr: 'Randevu, başvuru, etkinlik', AppLanguage.en: 'Appointments, applications, events', AppLanguage.ru: 'Встречи, заявки, события'},
    'explore_cat_events_sub': {AppLanguage.tr: 'Kampüs etkinlikleri', AppLanguage.en: 'Campus events', AppLanguage.ru: 'События кампуса'},
    'explore_cat_sports_sub': {AppLanguage.tr: 'Takımlar ve tesisler', AppLanguage.en: 'Teams and facilities', AppLanguage.ru: 'Команды и объекты'},
    'explore_cat_clubs_sub': {AppLanguage.tr: 'Öğrenci kulüpleri', AppLanguage.en: 'Student clubs', AppLanguage.ru: 'Студенческие клубы'},
    'explore_cat_food_sub': {AppLanguage.tr: 'Menü ve saatler', AppLanguage.en: 'Menus and hours', AppLanguage.ru: 'Меню и часы'},
    'explore_cat_places_sub': {AppLanguage.tr: 'Kampüs noktaları', AppLanguage.en: 'Campus spots', AppLanguage.ru: 'Точки кампуса'},
    'explore_map_banner_kicker': {AppLanguage.tr: 'KAMPÜS REHBERİ', AppLanguage.en: 'CAMPUS GUIDE', AppLanguage.ru: 'ГИД ПО КАМПУСУ'},
    'explore_map_banner_title': {AppLanguage.tr: 'Kampüsü Daha Yakından Keşfet', AppLanguage.en: 'Explore the Campus Up Close', AppLanguage.ru: 'Изучите кампус подробнее'},
    'explore_map_banner_subtitle': {AppLanguage.tr: 'Harita ile önemli noktaları ve hizmetleri incele.', AppLanguage.en: 'Browse key spots and services on the map.', AppLanguage.ru: 'Изучайте важные точки и услуги на карте.'},
    'explore_recommended': {AppLanguage.tr: 'Senin İçin Önerilenler', AppLanguage.en: 'Recommended for You', AppLanguage.ru: 'Рекомендуем для вас'},
    'explore_recommend_today_events': {AppLanguage.tr: 'Bugünkü Etkinlikler', AppLanguage.en: "Today's Events", AppLanguage.ru: 'События сегодня'},
    'explore_recommend_today_events_suffix': {AppLanguage.tr: 'etkinlik bugün', AppLanguage.en: 'events today', AppLanguage.ru: 'событий сегодня'},
    'explore_recommend_today_events_empty': {AppLanguage.tr: 'Bugün planlanan etkinlik yok', AppLanguage.en: 'No events planned today', AppLanguage.ru: 'На сегодня событий нет'},
    'explore_recommend_popular_places': {AppLanguage.tr: 'En Popüler Yerler', AppLanguage.en: 'Most Popular Places', AppLanguage.ru: 'Самые популярные места'},
    'explore_recommend_popular_suffix': {AppLanguage.tr: 'kişi check-in yaptı', AppLanguage.en: 'recent check-ins', AppLanguage.ru: 'отметок'},
    'explore_recommend_popular_places_empty': {AppLanguage.tr: 'Henüz check-in yok — ilk sen ol', AppLanguage.en: 'No check-ins yet — be the first', AppLanguage.ru: 'Пока нет отметок — будьте первым'},
    'explore_search_hint': {AppLanguage.tr: 'Yer, etkinlik, kulüp veya spor ara…', AppLanguage.en: 'Search places, events, clubs or sports…', AppLanguage.ru: 'Поиск мест, событий, клубов или спорта…'},
    'catalog_filter_all': {AppLanguage.tr: 'Tümü', AppLanguage.en: 'All', AppLanguage.ru: 'Все'},
    'profile_gallery': {AppLanguage.tr: 'Galerim', AppLanguage.en: 'My Gallery', AppLanguage.ru: 'Моя галерея'},
    'profile_gallery_empty': {AppLanguage.tr: 'Henüz fotoğraf yok. İlk anını yükle.', AppLanguage.en: 'No photos yet. Upload your first memory.', AppLanguage.ru: 'Пока нет фото. Загрузите первое.'},
    'profile_gallery_add': {AppLanguage.tr: 'Fotoğraf ekle', AppLanguage.en: 'Add photo', AppLanguage.ru: 'Добавить фото'},
    'achievements_title': {AppLanguage.tr: 'Başarımlar', AppLanguage.en: 'Achievements', AppLanguage.ru: 'Достижения'},
    'achievements_locked': {AppLanguage.tr: 'Kilitli', AppLanguage.en: 'Locked', AppLanguage.ru: 'Закрыто'},
    'career_opportunities': {AppLanguage.tr: 'Fırsatlar', AppLanguage.en: 'Opportunities', AppLanguage.ru: 'Возможности'},
    'career_profile': {AppLanguage.tr: 'Kariyer Profilim', AppLanguage.en: 'My Career Profile', AppLanguage.ru: 'Мой карьерный профиль'},
    'career_empty_ops': {AppLanguage.tr: 'Şu an yayınlanmış fırsat yok.', AppLanguage.en: 'No published opportunities right now.', AppLanguage.ru: 'Пока нет опубликованных возможностей.'},
    'help_title': {AppLanguage.tr: 'Yardım', AppLanguage.en: 'Help', AppLanguage.ru: 'Помощь'},

    // Explore
    'explore_title': {AppLanguage.tr: 'Kampüsü Keşfet', AppLanguage.en: 'Explore Campus', AppLanguage.ru: 'Исследуй кампус'},
    'explore_places_suffix': {AppLanguage.tr: 'yer', AppLanguage.en: 'places', AppLanguage.ru: 'мест'},
    'explore_busiest': {AppLanguage.tr: 'Şu an en yoğun', AppLanguage.en: 'Busiest right now', AppLanguage.ru: 'Сейчас многолюдно'},
    'explore_campuses': {AppLanguage.tr: 'Kampüsler', AppLanguage.en: 'Campuses', AppLanguage.ru: 'Кампусы'},
    'explore_load_more': {AppLanguage.tr: 'Daha fazla göster', AppLanguage.en: 'Show more', AppLanguage.ru: 'Показать ещё'},
    'explore_all_listed': {AppLanguage.tr: 'Tüm', AppLanguage.en: 'All', AppLanguage.ru: 'Все'},
    'explore_listed_suffix': {AppLanguage.tr: 'yer listelendi', AppLanguage.en: 'places listed', AppLanguage.ru: 'мест показано'},
    'explore_empty_category': {AppLanguage.tr: 'Bu kategoride yer yok', AppLanguage.en: 'No places in this category', AppLanguage.ru: 'В этой категории нет мест'},
    'explore_tour': {AppLanguage.tr: '360° Tur', AppLanguage.en: '360° Tour', AppLanguage.ru: '360° тур'},
    'explore_points_suffix': {AppLanguage.tr: 'nokta', AppLanguage.en: 'points', AppLanguage.ru: 'точек'},
    'category_all': {AppLanguage.tr: 'Tümü', AppLanguage.en: 'All', AppLanguage.ru: 'Все'},

    // Social
    'social_title': {AppLanguage.tr: 'Kampüs Akışı', AppLanguage.en: 'Campus Feed', AppLanguage.ru: 'Лента кампуса'},
    'social_tagline': {AppLanguage.tr: 'Kampüs anlarını paylaş, birlikte keşfet.', AppLanguage.en: 'Share campus moments, explore together.', AppLanguage.ru: 'Делись моментами кампуса вместе с другими.'},
    'social_share': {AppLanguage.tr: 'Paylaş', AppLanguage.en: 'Share', AppLanguage.ru: 'Опубликовать'},
    'social_empty': {AppLanguage.tr: 'Henüz paylaşım yok · ilk gönderiyi sen paylaş', AppLanguage.en: 'No posts yet · be the first to share', AppLanguage.ru: 'Пока нет постов · опубликуй первым'},
    'social_load_more': {AppLanguage.tr: 'Daha fazla göster', AppLanguage.en: 'Show more', AppLanguage.ru: 'Показать ещё'},
    'social_new_story': {AppLanguage.tr: 'Hikaye ekle', AppLanguage.en: 'Add story', AppLanguage.ru: 'Добавить историю'},
    'social_new_post': {AppLanguage.tr: 'Yeni gönderi', AppLanguage.en: 'New post', AppLanguage.ru: 'Новый пост'},
    'social_visibility': {AppLanguage.tr: 'Kimler görsün?', AppLanguage.en: 'Who can see this?', AppLanguage.ru: 'Кто увидит?'},
    'social_visibility_everyone': {AppLanguage.tr: 'Herkes', AppLanguage.en: 'Everyone', AppLanguage.ru: 'Все'},
    'social_visibility_friends': {AppLanguage.tr: 'Arkadaşlarım', AppLanguage.en: 'Friends', AppLanguage.ru: 'Друзья'},
    'social_visibility_only_me': {AppLanguage.tr: 'Sadece ben', AppLanguage.en: 'Only me', AppLanguage.ru: 'Только я'},
    'social_people_nearby': {AppLanguage.tr: 'Yakındaki İnsanlar', AppLanguage.en: 'People Nearby', AppLanguage.ru: 'Люди рядом'},
    'social_report_post': {AppLanguage.tr: 'Şikayet Et', AppLanguage.en: 'Report', AppLanguage.ru: 'Пожаловаться'},
    'social_feed_tab': {AppLanguage.tr: 'Akış', AppLanguage.en: 'Feed', AppLanguage.ru: 'Лента'},
    'social_messages': {AppLanguage.tr: 'Mesajlar', AppLanguage.en: 'Messages', AppLanguage.ru: 'Сообщения'},
    'social_follow': {AppLanguage.tr: 'Takip Et', AppLanguage.en: 'Follow', AppLanguage.ru: 'Подписаться'},
    'social_following': {AppLanguage.tr: 'Takipte', AppLanguage.en: 'Following', AppLanguage.ru: 'Подписан'},
    'social_block': {AppLanguage.tr: 'Engelle', AppLanguage.en: 'Block', AppLanguage.ru: 'Заблокировать'},

    // Profile
    'profile_my_posts': {AppLanguage.tr: 'Gönderilerim', AppLanguage.en: 'My Posts', AppLanguage.ru: 'Мои публикации'},
    'profile_following_count': {AppLanguage.tr: 'Takip Ettiklerim', AppLanguage.en: 'Following', AppLanguage.ru: 'Подписки'},
    'profile_activity': {AppLanguage.tr: 'Aktivite Geçmişi', AppLanguage.en: 'Activity History', AppLanguage.ru: 'История активности'},
    'profile_activity_empty': {AppLanguage.tr: 'Henüz işlem yok. Check-in yap, etkinliğe katıl, yorum yaz ya da bir gönderiyi beğen — burada görünecek.', AppLanguage.en: "No activity yet. Check in, join an event, comment, or like a post — it'll show up here.", AppLanguage.ru: 'Пока нет активности. Отметься, присоединись к событию, оставь комментарий или лайк — это появится здесь.'},
    'profile_settings': {AppLanguage.tr: 'Ayarlar', AppLanguage.en: 'Settings', AppLanguage.ru: 'Настройки'},
    'profile_show_on_campus': {AppLanguage.tr: 'Kampüste beni göster', AppLanguage.en: 'Show me on campus', AppLanguage.ru: 'Показывать меня в кампусе'},
    'profile_show_on_campus_sub': {AppLanguage.tr: 'Varsayılan kapalı · arkadaş/etkinlik bağlamı için', AppLanguage.en: 'Off by default · for friends/event context', AppLanguage.ru: 'По умолчанию выкл. · для друзей/событий'},
    'profile_personalized': {AppLanguage.tr: 'Kişiselleştirilmiş öneriler', AppLanguage.en: 'Personalized recommendations', AppLanguage.ru: 'Персональные рекомендации'},
    'profile_personalized_sub': {AppLanguage.tr: 'İlgi alanı ve aktiviteye göre öneri', AppLanguage.en: 'Based on your interests and activity', AppLanguage.ru: 'На основе интересов и активности'},
    'profile_checkin_visibility': {AppLanguage.tr: "Check-in'lerimi akışta göster", AppLanguage.en: 'Show my check-ins in the feed', AppLanguage.ru: 'Показывать мои отметки в ленте'},
    'profile_checkin_visibility_sub': {AppLanguage.tr: 'Kapalıysa check-in yine XP kazandırır ama sosyal akışa düşmez', AppLanguage.en: "When off, check-ins still earn XP but won't post to the feed", AppLanguage.ru: 'Если выключено, отметки всё ещё дают XP, но не публикуются в ленте'},
    'profile_language': {AppLanguage.tr: 'Dil', AppLanguage.en: 'Language', AppLanguage.ru: 'Язык'},
    'profile_change_photo': {AppLanguage.tr: 'Fotoğrafı değiştir', AppLanguage.en: 'Change photo', AppLanguage.ru: 'Изменить фото'},
    'settings_section_info': {AppLanguage.tr: 'Kullanıcı Bilgileri', AppLanguage.en: 'User Info', AppLanguage.ru: 'Данные пользователя'},
    'settings_section_activity': {AppLanguage.tr: 'Kullanıcı Aktivitesi', AppLanguage.en: 'User Activity', AppLanguage.ru: 'Активность пользователя'},
    'settings_section_system': {AppLanguage.tr: 'Sistem Ayarları', AppLanguage.en: 'System Settings', AppLanguage.ru: 'Системные настройки'},

    // Quests / Leaderboard
    'quests_title': {AppLanguage.tr: 'Kampüs Sosyal Aktifliği', AppLanguage.en: 'Campus Social Activity', AppLanguage.ru: 'Социальная активность кампуса'},
    'quests_tagline': {AppLanguage.tr: 'Keşfet · katıl · check-in yap · XP kazan.', AppLanguage.en: 'Explore · join · check in · earn XP.', AppLanguage.ru: 'Исследуй · присоединяйся · отмечайся · получай XP.'},
    'quests_year_score': {AppLanguage.tr: 'YIL AKTİVİTESİ', AppLanguage.en: 'YEAR ACTIVITY', AppLanguage.ru: 'АКТИВНОСТЬ ГОДА'},
    'quests_leaderboard': {AppLanguage.tr: 'Liderlik Tablosu', AppLanguage.en: 'Leaderboard', AppLanguage.ru: 'Таблица лидеров'},
    'quests_suggestions': {AppLanguage.tr: 'Senin için öneriler', AppLanguage.en: 'Suggestions for you', AppLanguage.ru: 'Рекомендации для тебя'},

    // Place detail
    'place_checkin': {AppLanguage.tr: 'Check-in', AppLanguage.en: 'Check-in', AppLanguage.ru: 'Отметиться'},
    'place_checkin_busy': {AppLanguage.tr: 'Konum alınıyor…', AppLanguage.en: 'Locating…', AppLanguage.ru: 'Определяем…'},
    'place_navigate': {AppLanguage.tr: 'Yol Tarifi', AppLanguage.en: 'Navigate', AppLanguage.ru: 'Маршрут'},
    'place_tour': {AppLanguage.tr: '360° Keşfet', AppLanguage.en: '360° Explore', AppLanguage.ru: '360° тур'},
    'place_tour_tap': {AppLanguage.tr: '360° turu görüntülemek için dokun', AppLanguage.en: 'Tap to open the 360° tour', AppLanguage.ru: 'Нажмите, чтобы открыть 360°'},
    'place_cover_add': {AppLanguage.tr: 'Kapak fotoğrafı ekle', AppLanguage.en: 'Add cover photo', AppLanguage.ru: 'Добавить обложку'},
    'place_cover_change': {AppLanguage.tr: 'Kapak fotoğrafını değiştir', AppLanguage.en: 'Change cover photo', AppLanguage.ru: 'Сменить обложку'},
    'place_photos': {AppLanguage.tr: 'Fotoğraflar', AppLanguage.en: 'Photos', AppLanguage.ru: 'Фото'},
    'place_reviews': {AppLanguage.tr: 'Yorumlar', AppLanguage.en: 'Reviews', AppLanguage.ru: 'Отзывы'},
    'place_recent_checkins': {AppLanguage.tr: 'Son check-inler', AppLanguage.en: 'Recent check-ins', AppLanguage.ru: 'Недавние отметки'},
    'place_report': {AppLanguage.tr: 'Şikayet Et', AppLanguage.en: 'Report', AppLanguage.ru: 'Пожаловаться'},

    // Settings screen
    'settings_title': {AppLanguage.tr: 'Ayarlar', AppLanguage.en: 'Settings', AppLanguage.ru: 'Настройки'},
    'settings_account': {AppLanguage.tr: 'Hesap', AppLanguage.en: 'Account', AppLanguage.ru: 'Аккаунт'},
    'settings_forgot_password': {AppLanguage.tr: 'Şifremi Unuttum', AppLanguage.en: 'Forgot Password', AppLanguage.ru: 'Забыли пароль'},
    'settings_biometric': {AppLanguage.tr: 'Biyometrik Kimlik Doğrulama', AppLanguage.en: 'Biometric Authentication', AppLanguage.ru: 'Биометрическая аутентификация'},

    // Social — feed filters
    'social_filter_for_you': {AppLanguage.tr: 'Senin için', AppLanguage.en: 'For you', AppLanguage.ru: 'Для тебя'},
    'social_filter_following': {AppLanguage.tr: 'Takip Ettiklerim', AppLanguage.en: 'Following', AppLanguage.ru: 'Подписки'},
    'social_filter_friends': {AppLanguage.tr: 'Arkadaşlar', AppLanguage.en: 'Friends', AppLanguage.ru: 'Друзья'},
    'social_pin': {AppLanguage.tr: 'Pinle', AppLanguage.en: 'Pin', AppLanguage.ru: 'Закрепить'},
    'social_unpin': {AppLanguage.tr: 'Sabitlemeyi Kaldır', AppLanguage.en: 'Unpin', AppLanguage.ru: 'Открепить'},
    'profile_private': {AppLanguage.tr: 'Hesabımı gizle', AppLanguage.en: 'Hide my account', AppLanguage.ru: 'Скрыть аккаунт'},
    'profile_private_sub': {AppLanguage.tr: 'Açıksa takip etmeyenler gönderilerini ve profil içeriğini göremez', AppLanguage.en: 'When on, people who do not follow you cannot see your posts or profile content', AppLanguage.ru: 'Если включено, неподписанные не видят ваши публикации'},
    'social_filter_official': {AppLanguage.tr: 'Resmi', AppLanguage.en: 'Official', AppLanguage.ru: 'Официальные'},
    'social_filter_campus': {AppLanguage.tr: 'Kampüs', AppLanguage.en: 'Campus', AppLanguage.ru: 'Кампус'},
    'social_filter_courses': {AppLanguage.tr: 'Dersler', AppLanguage.en: 'Courses', AppLanguage.ru: 'Курсы'},
    'social_filter_events': {AppLanguage.tr: 'Etkinlikler', AppLanguage.en: 'Events', AppLanguage.ru: 'События'},

    // Post types
    'post_type_normal': {AppLanguage.tr: 'Paylaşım', AppLanguage.en: 'Post', AppLanguage.ru: 'Публикация'},
    'post_type_ders': {AppLanguage.tr: 'Ders', AppLanguage.en: 'Course', AppLanguage.ru: 'Курс'},
    'post_type_proje': {AppLanguage.tr: 'Proje', AppLanguage.en: 'Project', AppLanguage.ru: 'Проект'},
    'post_type_basari': {AppLanguage.tr: 'Başarı', AppLanguage.en: 'Achievement', AppLanguage.ru: 'Достижение'},
    'post_type_etkinlik': {AppLanguage.tr: 'Etkinlik', AppLanguage.en: 'Event', AppLanguage.ru: 'Событие'},

    // Social — misc (stories, comments, report flow)
    'social_notifications': {AppLanguage.tr: 'Bildirimler', AppLanguage.en: 'Notifications', AppLanguage.ru: 'Уведомления'},
    'social_add_photo': {AppLanguage.tr: 'Fotoğraf ekle', AppLanguage.en: 'Add photo', AppLanguage.ru: 'Добавить фото'},
    'social_or_write_text': {AppLanguage.tr: 'veya metin yaz...', AppLanguage.en: 'or write text...', AppLanguage.ru: 'или напиши текст...'},
    'social_write_comment': {AppLanguage.tr: 'Yorum yaz...', AppLanguage.en: 'Write a comment...', AppLanguage.ru: 'Написать комментарий...'},
    'social_comments_label': {AppLanguage.tr: 'Yorumlar', AppLanguage.en: 'Comments', AppLanguage.ru: 'Комментарии'},
    'social_no_comments_yet': {AppLanguage.tr: 'Henüz yorum yok · ilk yorumu sen yaz.', AppLanguage.en: 'No comments yet · be the first to comment.', AppLanguage.ru: 'Комментариев пока нет · будь первым.'},
    'social_empty_category': {AppLanguage.tr: 'Bu kategoride henüz içerik yok', AppLanguage.en: 'No content in this category yet', AppLanguage.ru: 'В этой категории пока нет контента'},
    'social_report_reason_spam': {AppLanguage.tr: 'Spam', AppLanguage.en: 'Spam', AppLanguage.ru: 'Спам'},
    'social_report_reason_inappropriate': {AppLanguage.tr: 'Uygunsuz içerik', AppLanguage.en: 'Inappropriate content', AppLanguage.ru: 'Неприемлемый контент'},
    'social_report_reason_harassment': {AppLanguage.tr: 'Taciz', AppLanguage.en: 'Harassment', AppLanguage.ru: 'Домогательство'},
    'social_report_reason_other': {AppLanguage.tr: 'Diğer', AppLanguage.en: 'Other', AppLanguage.ru: 'Другое'},
    'social_report_post_title': {AppLanguage.tr: 'Gönderiyi şikayet et', AppLanguage.en: 'Report post', AppLanguage.ru: 'Пожаловаться на пост'},
    'social_cancel': {AppLanguage.tr: 'Vazgeç', AppLanguage.en: 'Cancel', AppLanguage.ru: 'Отмена'},
    'social_send': {AppLanguage.tr: 'Gönder', AppLanguage.en: 'Send', AppLanguage.ru: 'Отправить'},
    'social_report_sent': {AppLanguage.tr: 'Şikayetin alındı, kampüs ekibine iletilecek.', AppLanguage.en: 'Your report was received and will be sent to the campus team.', AppLanguage.ru: 'Жалоба получена и будет передана команде кампуса.'},

    // Compose post sheet
    'compose_hint': {AppLanguage.tr: 'Kampüsteki bir anını paylaş...', AppLanguage.en: 'Share a moment from campus...', AppLanguage.ru: 'Поделись моментом из кампуса...'},
    'compose_add_photo': {AppLanguage.tr: 'Fotoğraf ekle (kamera/galeri)', AppLanguage.en: 'Add photo (camera/gallery)', AppLanguage.ru: 'Добавить фото (камера/галерея)'},
    'compose_add_media': {AppLanguage.tr: 'Fotoğraf veya video ekle', AppLanguage.en: 'Add photo or video', AppLanguage.ru: 'Добавить фото или видео'},
    'compose_video_attached': {AppLanguage.tr: 'Video eklendi (onay kuyruğu)', AppLanguage.en: 'Video attached (review queue)', AppLanguage.ru: 'Видео добавлено (очередь проверки)'},
    'compose_location_added': {AppLanguage.tr: 'Konum eklendi', AppLanguage.en: 'Location added', AppLanguage.ru: 'Локация добавлена'},
    'compose_add_location': {AppLanguage.tr: 'Konum ekle', AppLanguage.en: 'Add location', AppLanguage.ru: 'Добавить локацию'},
    'compose_course_added': {AppLanguage.tr: 'Ders eklendi', AppLanguage.en: 'Course added', AppLanguage.ru: 'Курс добавлен'},
    'compose_add_course': {AppLanguage.tr: 'Ders ekle', AppLanguage.en: 'Add course', AppLanguage.ru: 'Добавить курс'},
    'compose_location_hint': {AppLanguage.tr: 'Örn. Atelier, The Garden…', AppLanguage.en: 'E.g. Atelier, The Garden…', AppLanguage.ru: 'Напр. Atelier, The Garden…'},
    'compose_course_hint': {AppLanguage.tr: 'Örn. Veri Yapıları', AppLanguage.en: 'E.g. Data Structures', AppLanguage.ru: 'Напр. Структуры данных'},
    'compose_hashtag_tip': {AppLanguage.tr: "İpucu: metnine #hashtag ekleyerek Keşfet'te bulunabilir yap.", AppLanguage.en: "Tip: add a #hashtag to your text so it's discoverable in Explore.", AppLanguage.ru: 'Совет: добавь #хэштег в текст, чтобы его можно было найти в разделе Обзор.'},
    'compose_default_caption': {AppLanguage.tr: 'bir fotoğraf paylaştı', AppLanguage.en: 'shared a photo', AppLanguage.ru: 'поделился(-ась) фото'},

    // Notifications screen
    'notif_title': {AppLanguage.tr: 'Bildirimler', AppLanguage.en: 'Notifications', AppLanguage.ru: 'Уведомления'},
    'notif_scope_banner': {AppLanguage.tr: 'Resmi ARUCAD duyuruları, etkinlik durumları ve sana yönelik etkileşim bildirimleri burada görünür.', AppLanguage.en: 'Official ARUCAD announcements, event updates, and notifications addressed to you appear here.', AppLanguage.ru: 'Здесь отображаются официальные объявления ARUCAD, обновления событий и уведомления для вас.'},
    'notif_empty': {AppLanguage.tr: 'Henüz bildirim yok.', AppLanguage.en: 'No notifications yet.', AppLanguage.ru: 'Пока нет уведомлений.'},
    'notif_new_announcement': {AppLanguage.tr: '{name} yeni bir duyuru paylaştı', AppLanguage.en: '{name} shared a new announcement', AppLanguage.ru: '{name} опубликовал(а) новое объявление'},

    // People / Explore search
    'people_search_hint': {AppLanguage.tr: 'Öğrenci, ders, konu veya #hashtag ara...', AppLanguage.en: 'Search students, courses, topics or #hashtags...', AppLanguage.ru: 'Искать студентов, курсы, темы или #хэштеги...'},
    'people_no_results': {AppLanguage.tr: 'Sonuç bulunamadı', AppLanguage.en: 'No results found', AppLanguage.ru: 'Ничего не найдено'},
    'people_posts_section': {AppLanguage.tr: 'Gönderiler', AppLanguage.en: 'Posts', AppLanguage.ru: 'Публикации'},
    'people_popular_students': {AppLanguage.tr: 'Popüler öğrenciler', AppLanguage.en: 'Popular students', AppLanguage.ru: 'Популярные студенты'},
    'people_unblock': {AppLanguage.tr: 'Engeli Kaldır', AppLanguage.en: 'Unblock', AppLanguage.ru: 'Разблокировать'},
    'people_send_message': {AppLanguage.tr: 'Mesaj gönder', AppLanguage.en: 'Send message', AppLanguage.ru: 'Отправить сообщение'},

    // Profile — extras
    'profile_avatar_pick_device': {AppLanguage.tr: 'Kameradan çek / Galeriden seç', AppLanguage.en: 'Take a photo / Choose from gallery', AppLanguage.ru: 'Сделать фото / Выбрать из галереи'},
    'profile_avatar_preset': {AppLanguage.tr: 'veya hazır bir avatar seç', AppLanguage.en: 'or pick a preset avatar', AppLanguage.ru: 'или выбери готовый аватар'},
    'profile_welcome_title': {AppLanguage.tr: 'Kampüse Hoş Geldin', AppLanguage.en: 'Welcome to Campus', AppLanguage.ru: 'Добро пожаловать в кампус'},
    'profile_welcome_sub': {AppLanguage.tr: 'İlk 30 gün rehberi', AppLanguage.en: 'Your first 30 days guide', AppLanguage.ru: 'Гид на первые 30 дней'},
    'profile_nearby_toggle': {AppLanguage.tr: 'Beni yakın çevrede göster', AppLanguage.en: 'Show me nearby', AppLanguage.ru: 'Показывать меня рядом'},
    'profile_nearby_toggle_sub': {AppLanguage.tr: '"X şu an yakında" gibi bildirimlerde görünürsün — kapalıyken hiç görünmezsin', AppLanguage.en: 'You\'ll appear in "X is nearby" notifications — invisible when off', AppLanguage.ru: 'Ты будешь появляться в уведомлениях вида «X рядом» — при выключении тебя не видно'},
    'profile_bio_achievements': {AppLanguage.tr: 'Başarılar', AppLanguage.en: 'Achievements', AppLanguage.ru: 'Достижения'},
    'profile_bio_projects': {AppLanguage.tr: 'Projeler', AppLanguage.en: 'Projects', AppLanguage.ru: 'Проекты'},

    // Appointments
    'appt_title': {AppLanguage.tr: 'Randevu', AppLanguage.en: 'Appointment', AppLanguage.ru: 'Запись'},
    'appt_mine': {AppLanguage.tr: 'Mevcut randevularım', AppLanguage.en: 'My appointments', AppLanguage.ru: 'Мои записи'},
    'appt_mine_empty': {AppLanguage.tr: 'Henüz randevu yok.', AppLanguage.en: 'No appointments yet.', AppLanguage.ru: 'Записей пока нет.'},
    'appt_new': {AppLanguage.tr: 'Yeni randevu', AppLanguage.en: 'New appointment', AppLanguage.ru: 'Новая запись'},
    'appt_no_staff': {AppLanguage.tr: 'Randevu alınabilecek personel henüz tanımlanmamış.', AppLanguage.en: 'No bookable staff has been set up yet.', AppLanguage.ru: 'Персонал для записи ещё не задан.'},
    'appt_staff': {AppLanguage.tr: 'Sorumlu kişi *', AppLanguage.en: 'Staff member *', AppLanguage.ru: 'Сотрудник *'},
    'appt_time': {AppLanguage.tr: 'Saat *', AppLanguage.en: 'Time *', AppLanguage.ru: 'Время *'},
    'appt_subject': {AppLanguage.tr: 'Randevu konusu *', AppLanguage.en: 'Subject *', AppLanguage.ru: 'Тема *'},
    'appt_subject_hint': {AppLanguage.tr: 'Ne için geleceksiniz?', AppLanguage.en: 'What is this for?', AppLanguage.ru: 'По какому вопросу?'},
    'appt_notes': {AppLanguage.tr: 'Açıklama / not (isteğe bağlı)', AppLanguage.en: 'Notes (optional)', AppLanguage.ru: 'Комментарий (необязательно)'},
    'appt_create': {AppLanguage.tr: 'Oluştur', AppLanguage.en: 'Create', AppLanguage.ru: 'Создать'},
    'appt_creating': {AppLanguage.tr: 'Oluşturuluyor…', AppLanguage.en: 'Creating…', AppLanguage.ru: 'Создание…'},
    'appt_confirm_title': {AppLanguage.tr: 'Randevu oluştur', AppLanguage.en: 'Create appointment', AppLanguage.ru: 'Создать запись'},
    'appt_cancel_title': {AppLanguage.tr: 'Randevuyu iptal et?', AppLanguage.en: 'Cancel this appointment?', AppLanguage.ru: 'Отменить запись?'},
    'appt_cancel_action': {AppLanguage.tr: 'İptal et', AppLanguage.en: 'Cancel it', AppLanguage.ru: 'Отменить'},
    'appt_dismiss': {AppLanguage.tr: 'Vazgeç', AppLanguage.en: 'Back', AppLanguage.ru: 'Назад'},
    'appt_retry': {AppLanguage.tr: 'Tekrar dene', AppLanguage.en: 'Try again', AppLanguage.ru: 'Повторить'},
    'appt_pick_staff': {AppLanguage.tr: 'Sorumlu kişi seçin.', AppLanguage.en: 'Choose a staff member.', AppLanguage.ru: 'Выберите сотрудника.'},
    'appt_need_subject': {AppLanguage.tr: 'Randevu konusu zorunludur.', AppLanguage.en: 'A subject is required.', AppLanguage.ru: 'Тема обязательна.'},
    'appt_need_slot': {AppLanguage.tr: 'Müsait bir saat seçin.', AppLanguage.en: 'Choose an available time.', AppLanguage.ru: 'Выберите свободное время.'},
    'appt_created': {AppLanguage.tr: 'Randevu oluşturuldu.', AppLanguage.en: 'Appointment created.', AppLanguage.ru: 'Запись создана.'},
    'appt_cancelled': {AppLanguage.tr: 'Randevu iptal edildi.', AppLanguage.en: 'Appointment cancelled.', AppLanguage.ru: 'Запись отменена.'},
    'appt_no_slots': {AppLanguage.tr: 'Bu gün için müsait saat yok.', AppLanguage.en: 'No available times this day.', AppLanguage.ru: 'На этот день нет свободного времени.'},
    'appt_no_open': {AppLanguage.tr: 'Bu gün müsait saat kalmadı. Başka bir gün seçin.', AppLanguage.en: 'No open times left today. Pick another day.', AppLanguage.ru: 'Свободного времени не осталось. Выберите другой день.'},
    'appt_slot_taken': {AppLanguage.tr: 'Bu slot artık müsait değil.', AppLanguage.en: 'This slot is no longer available.', AppLanguage.ru: 'Этот слот уже занят.'},
    'appt_already': {AppLanguage.tr: 'Bu saatte zaten randevunuz var.', AppLanguage.en: 'You already have an appointment at this time.', AppLanguage.ru: 'У вас уже есть запись на это время.'},
    'appt_past': {AppLanguage.tr: 'Geçmiş slot seçilemez.', AppLanguage.en: 'Past slots cannot be booked.', AppLanguage.ru: 'Прошедшее время выбрать нельзя.'},
    'appt_forbidden': {AppLanguage.tr: 'Bu işlem için yetkiniz yok.', AppLanguage.en: 'You are not allowed to do this.', AppLanguage.ru: 'Недостаточно прав.'},
    'appt_status_pending': {AppLanguage.tr: 'Beklemede', AppLanguage.en: 'Pending', AppLanguage.ru: 'Ожидает'},
    'appt_status_approved': {AppLanguage.tr: 'Onaylandı', AppLanguage.en: 'Approved', AppLanguage.ru: 'Одобрено'},
    'appt_status_rejected': {AppLanguage.tr: 'Reddedildi', AppLanguage.en: 'Rejected', AppLanguage.ru: 'Отклонено'},
    'appt_status_cancelled': {AppLanguage.tr: 'İptal', AppLanguage.en: 'Cancelled', AppLanguage.ru: 'Отменено'},
    'appt_status_completed': {AppLanguage.tr: 'Tamamlandı', AppLanguage.en: 'Completed', AppLanguage.ru: 'Завершено'},
    'appt_cancel': {AppLanguage.tr: 'İptal', AppLanguage.en: 'Cancel', AppLanguage.ru: 'Отмена'},
    'common_logout': {AppLanguage.tr: 'Çıkış yap', AppLanguage.en: 'Log out', AppLanguage.ru: 'Выйти'},
    'common_logout_confirm': {AppLanguage.tr: 'Hesabından çıkış yapmak istediğine emin misin?', AppLanguage.en: 'Log out of this account?', AppLanguage.ru: 'Выйти из этого аккаунта?'},
    'common_ok': {AppLanguage.tr: 'Tamam', AppLanguage.en: 'OK', AppLanguage.ru: 'ОК'},
    'common_continue': {AppLanguage.tr: 'Devam', AppLanguage.en: 'Continue', AppLanguage.ru: 'Продолжить'},
    'common_cancel': {AppLanguage.tr: 'Vazgeç', AppLanguage.en: 'Cancel', AppLanguage.ru: 'Отмена'},
    'common_remove': {AppLanguage.tr: 'Kaldır', AppLanguage.en: 'Remove', AppLanguage.ru: 'Удалить'},

    // Login
    'login_identifier': {AppLanguage.tr: 'E-posta veya Öğrenci No', AppLanguage.en: 'Email or Student ID', AppLanguage.ru: 'Email или номер студента'},
    'login_password': {AppLanguage.tr: 'Şifre', AppLanguage.en: 'Password', AppLanguage.ru: 'Пароль'},
    'login_remember': {AppLanguage.tr: 'Beni Hatırla', AppLanguage.en: 'Remember me', AppLanguage.ru: 'Запомнить меня'},
    'login_submit': {AppLanguage.tr: 'Giriş Yap', AppLanguage.en: 'Sign in', AppLanguage.ru: 'Войти'},
    'login_microsoft': {AppLanguage.tr: 'Microsoft ile Giriş Yap', AppLanguage.en: 'Sign in with Microsoft', AppLanguage.ru: 'Войти через Microsoft'},
    'login_failed': {AppLanguage.tr: 'Giriş yapılamadı. Bilgilerini kontrol et.', AppLanguage.en: 'Sign-in failed. Check your details.', AppLanguage.ru: 'Не удалось войти. Проверьте данные.'},
    'login_biometric_face': {AppLanguage.tr: 'Face ID ile giriş yap', AppLanguage.en: 'Sign in with Face ID', AppLanguage.ru: 'Войти с Face ID'},
    'login_biometric_finger': {AppLanguage.tr: 'Parmak izi ile giriş yap', AppLanguage.en: 'Sign in with fingerprint', AppLanguage.ru: 'Войти по отпечатку'},

    // Appearance
    'theme_appearance': {AppLanguage.tr: 'Görünüm', AppLanguage.en: 'Appearance', AppLanguage.ru: 'Оформление'},
    'theme_system': {AppLanguage.tr: 'Sistem ayarını kullan', AppLanguage.en: 'Use system setting', AppLanguage.ru: 'Как в системе'},
    'theme_light': {AppLanguage.tr: 'Açık tema', AppLanguage.en: 'Light', AppLanguage.ru: 'Светлая'},
    'theme_dark': {AppLanguage.tr: 'Koyu tema', AppLanguage.en: 'Dark', AppLanguage.ru: 'Тёмная'},
    'theme_system_sub': {AppLanguage.tr: 'Telefonun açık/koyu modunu takip eder.', AppLanguage.en: 'Follows your phone light/dark mode.', AppLanguage.ru: 'Следует светлой/тёмной теме телефона.'},

    // Settings extras
    'settings_forgot_body': {AppLanguage.tr: 'Şifre sıfırlama ARUCAD Bilgi İşlem tarafından yönetilir. Lütfen kurumsal e-postan üzerinden destek@arucad.edu.tr adresine başvur.', AppLanguage.en: 'Password resets are handled by ARUCAD IT. Email destek@arucad.edu.tr from your institutional address.', AppLanguage.ru: 'Сброс пароля выполняет IT ARUCAD. Напишите на destek@arucad.edu.tr с корпоративной почты.'},
    'settings_biometric_unavailable': {AppLanguage.tr: 'Bu cihazda biyometrik doğrulama kullanılamıyor.', AppLanguage.en: 'Biometrics are not available on this device.', AppLanguage.ru: 'Биометрия на этом устройстве недоступна.'},
    'settings_biometric_face_on': {AppLanguage.tr: 'Face ID etkin', AppLanguage.en: 'Face ID enabled', AppLanguage.ru: 'Face ID включён'},
    'settings_biometric_finger_on': {AppLanguage.tr: 'Parmak izi etkin', AppLanguage.en: 'Fingerprint enabled', AppLanguage.ru: 'Отпечаток включён'},
    'settings_enroll_finger': {AppLanguage.tr: 'Parmak İzi ile Kaydol', AppLanguage.en: 'Enroll fingerprint', AppLanguage.ru: 'Зарегистрировать отпечаток'},
    'settings_enroll_face': {AppLanguage.tr: 'Face ID ile Kaydol', AppLanguage.en: 'Enroll Face ID', AppLanguage.ru: 'Зарегистрировать Face ID'},
    'settings_verify_title': {AppLanguage.tr: 'Kimliğini doğrula', AppLanguage.en: 'Verify your identity', AppLanguage.ru: 'Подтвердите личность'},
    'settings_bad_credentials': {AppLanguage.tr: 'E-posta veya şifre hatalı.', AppLanguage.en: 'Email or password is incorrect.', AppLanguage.ru: 'Неверный email или пароль.'},
    'settings_biometric_failed': {AppLanguage.tr: 'Biyometrik doğrulama tamamlanamadı.', AppLanguage.en: 'Biometric verification failed.', AppLanguage.ru: 'Биометрическая проверка не удалась.'},
    'settings_biometric_enabled': {AppLanguage.tr: 'Biyometrik giriş etkinleştirildi.', AppLanguage.en: 'Biometric sign-in enabled.', AppLanguage.ru: 'Биометрический вход включён.'},
    'settings_confirm_face': {AppLanguage.tr: 'Face ID kaydını onayla', AppLanguage.en: 'Confirm Face ID enrollment', AppLanguage.ru: 'Подтвердите Face ID'},
    'settings_confirm_finger': {AppLanguage.tr: 'Parmak izi kaydını onayla', AppLanguage.en: 'Confirm fingerprint enrollment', AppLanguage.ru: 'Подтвердите отпечаток'},
    'lang_tr': {AppLanguage.tr: 'Türkçe', AppLanguage.en: 'Türkçe', AppLanguage.ru: 'Türkçe'},
    'lang_en': {AppLanguage.tr: 'English', AppLanguage.en: 'English', AppLanguage.ru: 'English'},
    'lang_ru': {AppLanguage.tr: 'Русский', AppLanguage.en: 'Русский', AppLanguage.ru: 'Русский'},

    // In-app navigation
    'nav_mode_walking': {AppLanguage.tr: 'Yürüyerek', AppLanguage.en: 'Walk', AppLanguage.ru: 'Пешком'},
    'nav_mode_driving': {AppLanguage.tr: 'Araba', AppLanguage.en: 'Car', AppLanguage.ru: 'Авто'},
    'nav_mode_transit': {AppLanguage.tr: 'Otobüs', AppLanguage.en: 'Bus', AppLanguage.ru: 'Автобус'},
    'nav_min': {AppLanguage.tr: 'dk', AppLanguage.en: 'min', AppLanguage.ru: 'мин'},
    'nav_start': {AppLanguage.tr: 'Navigasyonu Başlat', AppLanguage.en: 'Start navigation', AppLanguage.ru: 'Начать маршрут'},
    'nav_finish': {AppLanguage.tr: 'Bitir', AppLanguage.en: 'Done', AppLanguage.ru: 'Готово'},
    'nav_campus_entrance': {AppLanguage.tr: 'Kampüs girişi', AppLanguage.en: 'Campus entrance', AppLanguage.ru: 'Вход в кампус'},
    'nav_ask_needs_session': {AppLanguage.tr: 'Ask ARUCAD için oturum açık olmalı.', AppLanguage.en: 'Sign in to use Ask ARUCAD.', AppLanguage.ru: 'Войдите, чтобы спросить ARUCAD.'},
    'nav_calculating': {AppLanguage.tr: '{mode} rotası hesaplanıyor…', AppLanguage.en: 'Calculating {mode} route…', AppLanguage.ru: 'Считаем маршрут ({mode})…'},
    'nav_fallback_origin': {AppLanguage.tr: 'Konum alınamadı; kampüs girişinden gösteriliyor.', AppLanguage.en: 'Location unavailable; showing from campus entrance.', AppLanguage.ru: 'Геолокация недоступна; старт от входа в кампус.'},

    'common_retry': {AppLanguage.tr: 'Tekrar dene', AppLanguage.en: 'Try again', AppLanguage.ru: 'Повторить'},
    'common_join': {AppLanguage.tr: 'Katıl', AppLanguage.en: 'Join', AppLanguage.ru: 'Присоединиться'},
    'common_joined': {AppLanguage.tr: 'Katıldın', AppLanguage.en: 'Joined', AppLanguage.ru: 'Вы в деле'},
    'common_about': {AppLanguage.tr: 'Hakkında', AppLanguage.en: 'About', AppLanguage.ru: 'О событии'},
    'common_place': {AppLanguage.tr: 'Yer', AppLanguage.en: 'Place', AppLanguage.ru: 'Место'},
    'common_time': {AppLanguage.tr: 'Saat', AppLanguage.en: 'Time', AppLanguage.ru: 'Время'},

    'event_attendees': {AppLanguage.tr: 'Katılımcı', AppLanguage.en: 'Attendees', AppLanguage.ru: 'Участники'},
    'event_going_count': {AppLanguage.tr: '{n} kişi gidiyor', AppLanguage.en: '{n} going', AppLanguage.ru: '{n} идут'},
    'event_organizer': {AppLanguage.tr: 'Organizatör', AppLanguage.en: 'Organizer', AppLanguage.ru: 'Организатор'},
    'event_reward': {AppLanguage.tr: 'Ödül', AppLanguage.en: 'Reward', AppLanguage.ru: 'Награда'},
    'event_audience': {AppLanguage.tr: 'Hedef Kitle', AppLanguage.en: 'Audience', AppLanguage.ru: 'Аудитория'},
    'event_view_venue': {AppLanguage.tr: 'Mekanı Gör · {name}', AppLanguage.en: 'View place · {name}', AppLanguage.ru: 'Место · {name}'},
    'event_venue_sub': {AppLanguage.tr: 'yorumlar, 360° tur ve yol tarifi', AppLanguage.en: 'reviews, 360° tour & directions', AppLanguage.ru: 'отзывы, 360° и маршрут'},

    // Home extras
    'home_load_failed': {AppLanguage.tr: 'Ana sayfa yüklenemedi', AppLanguage.en: 'Home could not load', AppLanguage.ru: 'Не удалось загрузить главную'},
    'home_events_today_count': {AppLanguage.tr: 'Kampüste {n} yaklaşan etkinlik var.', AppLanguage.en: 'There are {n} upcoming events on campus.', AppLanguage.ru: 'На кампусе {n} ближайших событий.'},
    'home_explore_today': {AppLanguage.tr: 'Etkinlikleri Keşfet', AppLanguage.en: 'Explore Events', AppLanguage.ru: 'Смотреть события'},
    'home_event_item': {AppLanguage.tr: 'etkinlik', AppLanguage.en: 'event', AppLanguage.ru: 'событие'},
    'home_campus_now': {AppLanguage.tr: 'Kampüste Şimdi', AppLanguage.en: 'On Campus Now', AppLanguage.ru: 'Сейчас в кампусе'},
    'home_see_social': {AppLanguage.tr: 'Sosyali Gör', AppLanguage.en: 'See Social', AppLanguage.ru: 'В соцсеть'},

    // Home — greeting card. Times: 05–11 morning, 12–17 day, 18–21 evening,
    // 22–04 night.
    'greet_morning': {AppLanguage.tr: 'Günaydın', AppLanguage.en: 'Good morning', AppLanguage.ru: 'Доброе утро'},
    'greet_day': {AppLanguage.tr: 'İyi günler', AppLanguage.en: 'Good afternoon', AppLanguage.ru: 'Добрый день'},
    'greet_evening': {AppLanguage.tr: 'İyi akşamlar', AppLanguage.en: 'Good evening', AppLanguage.ru: 'Добрый вечер'},
    'greet_night': {AppLanguage.tr: 'İyi geceler', AppLanguage.en: 'Good night', AppLanguage.ru: 'Доброй ночи'},

    // One line per weekday (1 = Monday, matching DateTime.weekday).
    'greet_line_1': {AppLanguage.tr: 'Yeni hafta, yeni fikirler için boş bir tuval.', AppLanguage.en: 'A new week: an empty canvas for new ideas.', AppLanguage.ru: 'Новая неделя — чистый холст для новых идей.'},
    'greet_line_2': {AppLanguage.tr: 'Küçük bir adım bile seni yeni bir yere götürebilir.', AppLanguage.en: 'Even a small step can take you somewhere new.', AppLanguage.ru: 'Даже маленький шаг может привести вас к новому.'},
    'greet_line_3': {AppLanguage.tr: 'Haftanın ortası; ritmini bul ve devam et.', AppLanguage.en: 'Midweek — find your rhythm and keep going.', AppLanguage.ru: 'Середина недели: найдите свой ритм и продолжайте.'},
    'greet_line_4': {AppLanguage.tr: 'Merak ettiğin şeylerin peşinden git.', AppLanguage.en: 'Follow the things you are curious about.', AppLanguage.ru: 'Идите за тем, что вам любопытно.'},
    'greet_line_5': {AppLanguage.tr: 'Haftayı güzel bir iz bırakarak tamamla.', AppLanguage.en: 'Finish the week leaving a good mark.', AppLanguage.ru: 'Завершите неделю, оставив добрый след.'},
    'greet_line_6': {AppLanguage.tr: 'Biraz keşfet, biraz dinlen, biraz da kendine zaman ayır.', AppLanguage.en: 'Explore a little, rest a little, keep some time for yourself.', AppLanguage.ru: 'Немного исследуйте, немного отдохните, оставьте время себе.'},
    'greet_line_7': {AppLanguage.tr: 'Yeni haftaya yer açmak için bugün biraz yavaşla.', AppLanguage.en: 'Slow down today to make room for the week ahead.', AppLanguage.ru: 'Сбавьте темп сегодня, чтобы освободить место новой неделе.'},

    // Home — feedback / surveys
    'home_feedback': {AppLanguage.tr: 'Geri Bildirim', AppLanguage.en: 'Feedback', AppLanguage.ru: 'Обратная связь'},
    'home_feedback_title': {AppLanguage.tr: 'Kampüs anketleri', AppLanguage.en: 'Campus surveys', AppLanguage.ru: 'Опросы кампуса'},
    'home_feedback_sub': {AppLanguage.tr: 'Görüşünü paylaş', AppLanguage.en: 'Share your opinion', AppLanguage.ru: 'Поделись мнением'},
    'home_feedback_none': {AppLanguage.tr: 'Şu an yanıtını bekleyen bir anket yok.', AppLanguage.en: 'No survey is waiting for your answer right now.', AppLanguage.ru: 'Сейчас нет опросов, ожидающих вашего ответа.'},
    'home_feedback_error': {AppLanguage.tr: 'Anketler yüklenemedi. Tekrar deneyin.', AppLanguage.en: 'Could not load surveys. Please try again.', AppLanguage.ru: 'Не удалось загрузить опросы. Попробуйте снова.'},
    'home_create_activity': {AppLanguage.tr: 'Kendi Aktiviteni Oluştur', AppLanguage.en: 'Create Your Activity', AppLanguage.ru: 'Создать активность'},
    'home_need_help': {AppLanguage.tr: 'İhtiyacın mı var?', AppLanguage.en: 'Need help?', AppLanguage.ru: 'Нужна помощь?'},
    'home_all_services': {AppLanguage.tr: 'Tüm Hizmetler', AppLanguage.en: 'All Services', AppLanguage.ru: 'Все услуги'},
    'home_welcome': {AppLanguage.tr: 'Kampüse Hoş Geldin', AppLanguage.en: 'Welcome to Campus', AppLanguage.ru: 'Добро пожаловать'},
    'home_tasks_left': {AppLanguage.tr: '{n} görev kaldı', AppLanguage.en: '{n} tasks left', AppLanguage.ru: 'Осталось заданий: {n}'},
    'home_off_campus': {AppLanguage.tr: 'Şu an kampüs dışındasın — check-in ve canlı harita kampüs içinde çalışır.', AppLanguage.en: 'You are off campus — check-in and the live map work on campus.', AppLanguage.ru: 'Вы вне кампуса — check-in и карта работают на территории.'},
    'home_all_years': {AppLanguage.tr: 'Tüm Yıllar', AppLanguage.en: 'All Years', AppLanguage.ru: 'Все годы'},

    // Chat
    'chat_pick_person': {AppLanguage.tr: 'Kiminle konuşmak istersin?', AppLanguage.en: 'Who do you want to message?', AppLanguage.ru: 'Кому написать?'},
    'chat_title': {AppLanguage.tr: 'Mesajlar', AppLanguage.en: 'Messages', AppLanguage.ru: 'Сообщения'},
    'chat_empty': {AppLanguage.tr: 'Henüz mesajın yok.', AppLanguage.en: 'No messages yet.', AppLanguage.ru: 'Пока нет сообщений.'},
    'chat_new': {AppLanguage.tr: 'Yeni mesaj', AppLanguage.en: 'New message', AppLanguage.ru: 'Новое сообщение'},
    'chat_send_failed': {AppLanguage.tr: 'Mesaj gönderilemedi.', AppLanguage.en: 'Could not send message.', AppLanguage.ru: 'Не удалось отправить.'},
    'chat_thread_empty': {AppLanguage.tr: 'Henüz mesaj yok — ilk mesajı sen yaz.', AppLanguage.en: 'No messages yet — write the first one.', AppLanguage.ru: 'Пока пусто — напишите первым.'},
    'chat_hint': {AppLanguage.tr: 'Mesaj yaz...', AppLanguage.en: 'Write a message...', AppLanguage.ru: 'Напишите сообщение...'},
    'chat_realtime_note': {AppLanguage.tr: 'Yeni mesajlar anlık gelir. Bağlantı koparsa geçmiş yenilenir.', AppLanguage.en: 'New messages arrive live. History refreshes if the link drops.', AppLanguage.ru: 'Новые сообщения приходят сразу. При обрыве история обновится.'},

    // Ask ARUCAD
    'ask_new_chat': {AppLanguage.tr: 'Yeni Sohbet', AppLanguage.en: 'New chat', AppLanguage.ru: 'Новый чат'},
    'ask_empty': {AppLanguage.tr: 'Henüz sohbet yok — bir soru sorarak başla.', AppLanguage.en: 'No chats yet — ask a question to start.', AppLanguage.ru: 'Чатов нет — задайте вопрос.'},
    'ask_hint': {AppLanguage.tr: 'Kampüs, ders, servis veya etkinlik hakkında sor...', AppLanguage.en: 'Ask about campus, classes, services or events...', AppLanguage.ru: 'Спросите о кампусе, учёбе, услугах или событиях...'},
    'ask_directions': {AppLanguage.tr: 'Yol Tarifi', AppLanguage.en: 'Directions', AppLanguage.ru: 'Маршрут'},
    'ask_open': {AppLanguage.tr: 'Aç', AppLanguage.en: 'Open', AppLanguage.ru: 'Открыть'},
    'ask_preapply': {AppLanguage.tr: 'Ön Başvuru', AppLanguage.en: 'Pre-apply', AppLanguage.ru: 'Заявка'},
    'ask_details': {AppLanguage.tr: 'Detaylar', AppLanguage.en: 'Details', AppLanguage.ru: 'Подробнее'},
    'ask_open_club': {AppLanguage.tr: 'Kulübü Aç', AppLanguage.en: 'Open club', AppLanguage.ru: 'Открыть клуб'},
    'ask_preapply_join': {AppLanguage.tr: 'Ön Başvuru / Katıl', AppLanguage.en: 'Pre-apply / Join', AppLanguage.ru: 'Заявка / Вступить'},

    // Clubs
    'club_leave_title': {AppLanguage.tr: 'Kulüpten ayrıl?', AppLanguage.en: 'Leave this club?', AppLanguage.ru: 'Покинуть клуб?'},
    'club_leave_body': {AppLanguage.tr: '{name} üyeliğin sona erecek.', AppLanguage.en: 'Your membership in {name} will end.', AppLanguage.ru: 'Членство в {name} закончится.'},
    'club_leave': {AppLanguage.tr: 'Ayrıl', AppLanguage.en: 'Leave', AppLanguage.ru: 'Выйти'},
    'club_members_count': {AppLanguage.tr: '{n} üye', AppLanguage.en: '{n} members', AppLanguage.ru: '{n} участников'},
    'club_upcoming': {AppLanguage.tr: 'Yaklaşan Etkinlikler', AppLanguage.en: 'Upcoming Events', AppLanguage.ru: 'Ближайшие события'},
    'club_application_status': {AppLanguage.tr: 'Başvuru durumu', AppLanguage.en: 'Application status', AppLanguage.ru: 'Статус заявки'},
    'club_member_leave': {AppLanguage.tr: 'Üyesin · Ayrıl', AppLanguage.en: 'Member · Leave', AppLanguage.ru: 'Участник · Выйти'},
    'club_leaving': {AppLanguage.tr: 'Ayrılıyor…', AppLanguage.en: 'Leaving…', AppLanguage.ru: 'Выход…'},
    'club_complete_membership': {AppLanguage.tr: 'Üyeliği tamamla', AppLanguage.en: 'Complete membership', AppLanguage.ru: 'Завершить вступление'},
    'club_open_email_form': {AppLanguage.tr: 'E-postadaki formu aç', AppLanguage.en: 'Open email form', AppLanguage.ru: 'Открыть форму из письма'},
    'club_view_application': {AppLanguage.tr: 'Başvuruyu gör', AppLanguage.en: 'View application', AppLanguage.ru: 'Смотреть заявку'},
    'club_reapply': {AppLanguage.tr: 'Tekrar başvur', AppLanguage.en: 'Apply again', AppLanguage.ru: 'Подать снова'},

    // My applications
    'apps_title': {AppLanguage.tr: 'Başvurularım', AppLanguage.en: 'My Applications', AppLanguage.ru: 'Мои заявки'},
    'apps_load_failed': {AppLanguage.tr: 'Başvurular yüklenemedi.', AppLanguage.en: 'Could not load applications.', AppLanguage.ru: 'Не удалось загрузить заявки.'},
    'apps_empty': {AppLanguage.tr: 'Henüz bir başvurunuz yok.', AppLanguage.en: 'You have no applications yet.', AppLanguage.ru: 'Заявок пока нет.'},
    'apps_form': {AppLanguage.tr: 'Form', AppLanguage.en: 'Form', AppLanguage.ru: 'Форма'},

    // Need help / services hub
    'help_campus_services': {AppLanguage.tr: 'Kampüs Hizmetleri', AppLanguage.en: 'Campus Services', AppLanguage.ru: 'Услуги кампуса'},
    'help_campus_services_sub': {AppLanguage.tr: 'Öğrenci İşleri, PDR, kariyer, yurt, IT ve diğer birimler.', AppLanguage.en: 'Student Affairs, counseling, career, housing, IT and more.', AppLanguage.ru: 'Студенческий отдел, консультации, карьера, общежитие, IT и др.'},
    'help_services_search': {AppLanguage.tr: 'Hizmet ara', AppLanguage.en: 'Search services', AppLanguage.ru: 'Поиск услуг'},
    'help_services_filter': {AppLanguage.tr: 'Filtrele', AppLanguage.en: 'Filter', AppLanguage.ru: 'Фильтр'},
    'help_services_empty': {AppLanguage.tr: 'Hizmet bulunamadı', AppLanguage.en: 'No services found', AppLanguage.ru: 'Услуги не найдены'},
    'help_book_appointment': {AppLanguage.tr: 'Randevu al', AppLanguage.en: 'Book appointment', AppLanguage.ru: 'Записаться'},
    'help_book_appointment_sub': {AppLanguage.tr: 'Öğretim elemanı veya birim ile slot seç', AppLanguage.en: 'Pick a slot with staff or a unit', AppLanguage.ru: 'Выберите слот у сотрудника или отдела'},

    // Apply sheet
    'apply_how': {AppLanguage.tr: 'Nasıl katılmak istiyorsun?', AppLanguage.en: 'How do you want to join?', AppLanguage.ru: 'Как хотите участвовать?'},
    'apply_received': {AppLanguage.tr: 'Başvurunuz Alındı ✓', AppLanguage.en: 'Application received ✓', AppLanguage.ru: 'Заявка получена ✓'},
    'apply_already_member': {AppLanguage.tr: 'Zaten Katılımcısınız', AppLanguage.en: 'Already joined', AppLanguage.ru: 'Вы уже участник'},
    'apply_revision': {AppLanguage.tr: 'Revizyon Bekleniyor', AppLanguage.en: 'Revision requested', AppLanguage.ru: 'Нужна доработка'},
    'apply_reviewing': {AppLanguage.tr: 'Başvurunuz İnceleniyor', AppLanguage.en: 'Under review', AppLanguage.ru: 'На рассмотрении'},
    'apply_failed_title': {AppLanguage.tr: 'Başvuru Gönderilemedi', AppLanguage.en: 'Could not submit', AppLanguage.ru: 'Не удалось отправить'},
    'apply_failed_generic': {AppLanguage.tr: 'Bir şeyler ters gitti. Lütfen tekrar dene.', AppLanguage.en: 'Something went wrong. Please try again.', AppLanguage.ru: 'Что-то пошло не так. Попробуйте ещё раз.'},
    'apply_go_listing': {AppLanguage.tr: 'İlana Git', AppLanguage.en: 'Go to listing', AppLanguage.ru: 'К объявлению'},

    // Shared actions. Worth having once: these appear in a dozen dialogs,
    // and a per-screen copy is how translations drift apart.
    'act_cancel': {AppLanguage.tr: 'Vazgeç', AppLanguage.en: 'Cancel', AppLanguage.ru: 'Отмена'},
    'act_save': {AppLanguage.tr: 'Kaydet', AppLanguage.en: 'Save', AppLanguage.ru: 'Сохранить'},
    'act_send': {AppLanguage.tr: 'Gönder', AppLanguage.en: 'Send', AppLanguage.ru: 'Отправить'},
    'act_edit': {AppLanguage.tr: 'Düzenle', AppLanguage.en: 'Edit', AppLanguage.ru: 'Изменить'},
    'act_create': {AppLanguage.tr: 'Oluştur', AppLanguage.en: 'Create', AppLanguage.ru: 'Создать'},
    'act_apply': {AppLanguage.tr: 'Başvur', AppLanguage.en: 'Apply', AppLanguage.ru: 'Подать заявку'},
    'act_report': {AppLanguage.tr: 'Şikayet et', AppLanguage.en: 'Report', AppLanguage.ru: 'Пожаловаться'},
    'act_undoable': {AppLanguage.tr: 'Bu işlem geri alınamaz.', AppLanguage.en: 'This cannot be undone.', AppLanguage.ru: 'Это действие необратимо.'},
    'act_server_unreachable': {AppLanguage.tr: 'Sunucuya ulaşılamadı. Tekrar dene.', AppLanguage.en: 'Could not reach the server. Try again.', AppLanguage.ru: 'Сервер недоступен. Попробуйте снова.'},
    'act_report_sent': {AppLanguage.tr: 'Şikayet alındı. Moderasyon ekibi inceleyecek.', AppLanguage.en: 'Report received. The moderation team will review it.', AppLanguage.ru: 'Жалоба принята. Команда модерации её рассмотрит.'},
    'act_report_failed': {AppLanguage.tr: 'Şikayet gönderilemedi. Tekrar deneyin.', AppLanguage.en: 'Could not send the report. Please try again.', AppLanguage.ru: 'Не удалось отправить жалобу. Попробуйте снова.'},

    // Social — profile
    'sp_edit_post': {AppLanguage.tr: 'Gönderiyi düzenle', AppLanguage.en: 'Edit post', AppLanguage.ru: 'Редактировать пост'},
    'sp_delete_post_q': {AppLanguage.tr: 'Gönderi silinsin mi?', AppLanguage.en: 'Delete this post?', AppLanguage.ru: 'Удалить пост?'},
    'sp_archived': {AppLanguage.tr: 'Gönderi arşive alındı.', AppLanguage.en: 'Post archived.', AppLanguage.ru: 'Пост в архиве.'},
    'sp_archive_failed': {AppLanguage.tr: 'Arşivlenemedi.', AppLanguage.en: 'Could not archive.', AppLanguage.ru: 'Не удалось архивировать.'},
    'sp_unarchived': {AppLanguage.tr: 'Gönderi arşivden kaldırıldı.', AppLanguage.en: 'Post removed from archive.', AppLanguage.ru: 'Пост убран из архива.'},
    'sp_unarchive_failed': {AppLanguage.tr: 'Arşivden kaldırılamadı.', AppLanguage.en: 'Could not remove from archive.', AppLanguage.ru: 'Не удалось убрать из архива.'},
    'sp_unarchive': {AppLanguage.tr: 'Arşivden kaldır', AppLanguage.en: 'Remove from archive', AppLanguage.ru: 'Убрать из архива'},
    'sp_archive': {AppLanguage.tr: 'Arşive al', AppLanguage.en: 'Archive', AppLanguage.ru: 'В архив'},
    'sp_change_photo': {AppLanguage.tr: 'Fotoğrafı değiştir', AppLanguage.en: 'Change photo', AppLanguage.ru: 'Сменить фото'},
    'sp_photo_source': {AppLanguage.tr: 'Kameradan çek / Galeriden seç', AppLanguage.en: 'Take a photo / Choose from gallery', AppLanguage.ru: 'Снять / Выбрать из галереи'},
    'sp_photo_failed': {AppLanguage.tr: 'Profil fotoğrafı kaydedilemedi', AppLanguage.en: 'Could not save the profile photo', AppLanguage.ru: 'Не удалось сохранить фото профиля'},
    'sp_edit_profile': {AppLanguage.tr: 'Profili Düzenle', AppLanguage.en: 'Edit Profile', AppLanguage.ru: 'Редактировать профиль'},
    'sp_department': {AppLanguage.tr: 'Bölüm', AppLanguage.en: 'Department', AppLanguage.ru: 'Факультет'},
    'sp_year': {AppLanguage.tr: 'Sınıf', AppLanguage.en: 'Year', AppLanguage.ru: 'Курс'},
    'sp_university': {AppLanguage.tr: 'Üniversite', AppLanguage.en: 'University', AppLanguage.ru: 'Университет'},
    'sp_clubs_hint': {AppLanguage.tr: 'Kulüpler (her satıra bir tane)', AppLanguage.en: 'Clubs (one per line)', AppLanguage.ru: 'Клубы (по одному в строке)'},
    'sp_achievements_hint': {AppLanguage.tr: 'Başarılar (her satıra bir tane)', AppLanguage.en: 'Achievements (one per line)', AppLanguage.ru: 'Достижения (по одному в строке)'},
    'sp_projects_hint': {AppLanguage.tr: 'Projeler (her satıra bir tane)', AppLanguage.en: 'Projects (one per line)', AppLanguage.ru: 'Проекты (по одному в строке)'},
    'sp_following_empty': {AppLanguage.tr: 'Henüz kimseyi takip etmiyorsun.', AppLanguage.en: 'You are not following anyone yet.', AppLanguage.ru: 'Вы пока ни на кого не подписаны.'},
    'sp_block_toggled': {AppLanguage.tr: 'Engel durumu güncellendi', AppLanguage.en: 'Block status updated', AppLanguage.ru: 'Статус блокировки обновлён'},
    'sp_report_user_q': {AppLanguage.tr: 'Bu kullanıcıyı şikayet etmek istiyor musun?', AppLanguage.en: 'Do you want to report this user?', AppLanguage.ru: 'Пожаловаться на этого пользователя?'},
    'sp_block_toggle': {AppLanguage.tr: 'Engelle / Engeli kaldır', AppLanguage.en: 'Block / Unblock', AppLanguage.ru: 'Заблокировать / Разблокировать'},
    'sp_posts_count': {AppLanguage.tr: 'Gönderi', AppLanguage.en: 'Posts', AppLanguage.ru: 'Посты'},
    'sp_followers': {AppLanguage.tr: 'Takipçi', AppLanguage.en: 'Followers', AppLanguage.ru: 'Подписчики'},
    'sp_private': {AppLanguage.tr: 'Bu profil gizli. İçeriği yalnızca takipçileri görebilir.', AppLanguage.en: 'This profile is private. Only followers can see its content.', AppLanguage.ru: 'Это закрытый профиль. Содержимое видят только подписчики.'},
    'sp_posts_tab': {AppLanguage.tr: 'Gönderiler', AppLanguage.en: 'Posts', AppLanguage.ru: 'Посты'},
    'sp_archive_tab': {AppLanguage.tr: 'Arşivlerim', AppLanguage.en: 'My archive', AppLanguage.ru: 'Мой архив'},
    'sp_no_checkins': {AppLanguage.tr: 'Henüz check-in yapılmadı.', AppLanguage.en: 'No check-ins yet.', AppLanguage.ru: 'Отметок пока нет.'},
    'sp_no_saved': {AppLanguage.tr: 'Henüz kaydedilen gönderi yok.', AppLanguage.en: 'No saved posts yet.', AppLanguage.ru: 'Сохранённых постов пока нет.'},
    'sp_achievements': {AppLanguage.tr: 'Başarılar', AppLanguage.en: 'Achievements', AppLanguage.ru: 'Достижения'},

    // Place detail
    'pd_show_on_map': {AppLanguage.tr: 'Haritada göster', AppLanguage.en: 'Show on map', AppLanguage.ru: 'Показать на карте'},
    'pd_no_photos': {AppLanguage.tr: 'Bu oturumda henüz fotoğraf eklenmedi', AppLanguage.en: 'No photos added in this session yet', AppLanguage.ru: 'В этой сессии фото ещё не добавлены'},
    'pd_no_reviews': {AppLanguage.tr: 'Henüz yorum yok · ilk yorumu sen ekle.', AppLanguage.en: 'No reviews yet · be the first.', AppLanguage.ru: 'Отзывов пока нет · будьте первым.'},
    'pd_no_checkins': {AppLanguage.tr: 'Bu yerde henüz yakın zamanda check-in yok.', AppLanguage.en: 'No recent check-ins here yet.', AppLanguage.ru: 'Недавних отметок здесь пока нет.'},
    'pd_checkin_on_campus_only': {AppLanguage.tr: 'Check-in yalnızca ARUCAD kampüs sınırları içinde yapılabilir.', AppLanguage.en: 'Check-in is only possible inside the ARUCAD campus.', AppLanguage.ru: 'Отметиться можно только на территории кампуса ARUCAD.'},
    'pd_checkin_ok': {AppLanguage.tr: 'Check-in başarılı olacak', AppLanguage.en: 'Check-in will succeed', AppLanguage.ru: 'Отметка пройдёт успешно'},
    'pd_xp_once': {AppLanguage.tr: 'XP bir kez verilir. Sosyal paylaşım isteğe bağlı.', AppLanguage.en: 'XP is awarded once. Sharing is optional.', AppLanguage.ru: 'XP начисляется один раз. Публикация — по желанию.'},
    'pd_not_in_feed': {AppLanguage.tr: 'Akışa düşmez · +10 XP', AppLanguage.en: 'Not posted to the feed · +10 XP', AppLanguage.ru: 'Не попадёт в ленту · +10 XP'},
    'pd_share_social': {AppLanguage.tr: 'Sosyalde paylaş', AppLanguage.en: 'Share on Social', AppLanguage.ru: 'Поделиться в соцсети'},
    'pd_checkin_failed': {AppLanguage.tr: 'Check-in kaydedilemedi. Lütfen tekrar dene.', AppLanguage.en: 'Could not save the check-in. Please try again.', AppLanguage.ru: 'Не удалось сохранить отметку. Попробуйте снова.'},
    'pd_location_off': {AppLanguage.tr: 'Konum servisi kapalı — check-in için açmalısın.', AppLanguage.en: 'Location services are off — turn them on to check in.', AppLanguage.ru: 'Геолокация выключена — включите её, чтобы отметиться.'},
    'pd_location_denied': {AppLanguage.tr: 'Konum izni yok — check-in yapılamaz.', AppLanguage.en: 'No location permission — cannot check in.', AppLanguage.ru: 'Нет доступа к геолокации — отметка невозможна.'},
    'pd_location_failed': {AppLanguage.tr: 'Konum alınamadı — check-in yapılamaz.', AppLanguage.en: 'Could not get your location — cannot check in.', AppLanguage.ru: 'Не удалось определить местоположение — отметка невозможна.'},
    'pd_too_far': {AppLanguage.tr: 'Bu mekâna çok uzaksın (~{d} m). Check-in için {r} m içinde olmalısın.', AppLanguage.en: 'You are too far from this place (~{d} m). You must be within {r} m to check in.', AppLanguage.ru: 'Вы слишком далеко (~{d} м). Для отметки нужно быть в пределах {r} м.'},
    'pd_rate_place': {AppLanguage.tr: '{name} için puan ver', AppLanguage.en: 'Rate {name}', AppLanguage.ru: 'Оценить {name}'},
    'pd_review_thanks': {AppLanguage.tr: 'Yorumun eklendi, teşekkürler!', AppLanguage.en: 'Your review was added, thank you!', AppLanguage.ru: 'Ваш отзыв добавлен, спасибо!'},
    'pd_report_place': {AppLanguage.tr: 'Bu yeri şikayet et', AppLanguage.en: 'Report this place', AppLanguage.ru: 'Пожаловаться на это место'},
    'pd_note_optional': {AppLanguage.tr: 'Ek açıklama (opsiyonel)', AppLanguage.en: 'Additional note (optional)', AppLanguage.ru: 'Примечание (необязательно)'},
    'pd_report_received': {AppLanguage.tr: 'Şikayetin alındı, kampüs ekibine iletilecek.', AppLanguage.en: 'Your report was received and will reach the campus team.', AppLanguage.ru: 'Жалоба принята и будет передана команде кампуса.'},
    'pd_photo_added': {AppLanguage.tr: 'Fotoğraf eklendi', AppLanguage.en: 'Photo added', AppLanguage.ru: 'Фото добавлено'},

    // Social feed
    'sf_search_hint': {AppLanguage.tr: 'Öğrenci, ders, konu veya #hashtag ara...', AppLanguage.en: 'Search students, courses, topics or #hashtags...', AppLanguage.ru: 'Поиск студентов, курсов, тем или #хэштегов...'},
    'sf_clear_search': {AppLanguage.tr: 'Aramayı temizle', AppLanguage.en: 'Clear search', AppLanguage.ru: 'Очистить поиск'},
    'sf_like_failed': {AppLanguage.tr: 'Beğeni kaydedilemedi.', AppLanguage.en: 'Could not save the like.', AppLanguage.ru: 'Не удалось сохранить отметку «нравится».'},
    'sf_place_failed': {AppLanguage.tr: 'Mekân açılamadı.', AppLanguage.en: 'Could not open the place.', AppLanguage.ru: 'Не удалось открыть место.'},
    'sf_pin_failed': {AppLanguage.tr: 'Pin işlemi başarısız', AppLanguage.en: 'Could not pin the post', AppLanguage.ru: 'Не удалось закрепить пост'},
    'sf_viewers': {AppLanguage.tr: 'Görenler', AppLanguage.en: 'Viewers', AppLanguage.ru: 'Просмотры'},
    'sf_no_viewers': {AppLanguage.tr: 'Henüz kimse görmedi.', AppLanguage.en: 'Nobody has seen it yet.', AppLanguage.ru: 'Пока никто не видел.'},
    'sf_official': {AppLanguage.tr: 'RESMİ', AppLanguage.en: 'OFFICIAL', AppLanguage.ru: 'ОФИЦИАЛЬНО'},

    // Chat
    'ch_search_name': {AppLanguage.tr: 'İsim ara...', AppLanguage.en: 'Search a name...', AppLanguage.ru: 'Поиск по имени...'},
    'ch_no_match': {AppLanguage.tr: 'Eşleşen kimse yok', AppLanguage.en: 'No matches', AppLanguage.ru: 'Совпадений нет'},
    'ch_group_name': {AppLanguage.tr: 'Grup adı', AppLanguage.en: 'Group name', AppLanguage.ru: 'Название группы'},
    'ch_group_failed': {AppLanguage.tr: 'Grup oluşturulamadı. Sunucuya ulaşılamadı.', AppLanguage.en: 'Could not create the group. The server was unreachable.', AppLanguage.ru: 'Не удалось создать группу. Сервер недоступен.'},
    'ch_muted': {AppLanguage.tr: 'Sessize alındı', AppLanguage.en: 'Muted', AppLanguage.ru: 'Звук отключён'},
    'ch_mute_toggle': {AppLanguage.tr: 'Sessize al / Aç', AppLanguage.en: 'Mute / Unmute', AppLanguage.ru: 'Отключить / включить звук'},
    'ch_archive_toggle': {AppLanguage.tr: 'Arşive al / Çıkar', AppLanguage.en: 'Archive / Unarchive', AppLanguage.ru: 'В архив / из архива'},
    'ch_restrict_toggle': {AppLanguage.tr: 'Kısıtla / Kaldır', AppLanguage.en: 'Restrict / Unrestrict', AppLanguage.ru: 'Ограничить / снять'},

    // Career hub
    'cr_expertise': {AppLanguage.tr: 'Uzmanlık alanı', AppLanguage.en: 'Area of expertise', AppLanguage.ru: 'Область экспертизы'},
    'cr_view_cv': {AppLanguage.tr: 'CV görüntüle', AppLanguage.en: 'View CV', AppLanguage.ru: 'Посмотреть резюме'},
    'cr_cv_failed': {AppLanguage.tr: 'CV açılamadı', AppLanguage.en: 'Could not open the CV', AppLanguage.ru: 'Не удалось открыть резюме'},
    'cr_seeking_internship': {AppLanguage.tr: 'Staj arıyorum', AppLanguage.en: 'Looking for an internship', AppLanguage.ru: 'Ищу стажировку'},
    'cr_seeking_job': {AppLanguage.tr: 'İş arıyorum', AppLanguage.en: 'Looking for a job', AppLanguage.ru: 'Ищу работу'},
    'cr_office': {AppLanguage.tr: 'ARUCAD Kariyer ve Mezun Ofisi', AppLanguage.en: 'ARUCAD Career and Alumni Office', AppLanguage.ru: 'Офис карьеры и выпускников ARUCAD'},
    'cr_opportunities': {AppLanguage.tr: 'Fırsatlar', AppLanguage.en: 'Opportunities', AppLanguage.ru: 'Возможности'},
    'cr_search_opportunity': {AppLanguage.tr: 'Fırsat ara', AppLanguage.en: 'Search opportunities', AppLanguage.ru: 'Поиск возможностей'},
    'cr_no_listings': {AppLanguage.tr: 'Bu kategoride şu an ilan yok.', AppLanguage.en: 'No listings in this category right now.', AppLanguage.ru: 'В этой категории сейчас нет объявлений.'},
    'cr_consulting': {AppLanguage.tr: 'Bireysel Danışmanlık', AppLanguage.en: 'One-to-one consulting', AppLanguage.ru: 'Индивидуальные консультации'},
    'cr_no_consulting': {AppLanguage.tr: 'Şu an açık danışmanlık yok.', AppLanguage.en: 'No consulting slots are open right now.', AppLanguage.ru: 'Сейчас нет открытых консультаций.'},
    'cr_service_details': {AppLanguage.tr: 'Hizmet Detayları', AppLanguage.en: 'Service details', AppLanguage.ru: 'Детали услуги'},

    // Create your own activity
    'ca_submitted': {AppLanguage.tr: 'Başvurunuz bölüm başkanına gönderilmiştir. Detaylı işlem için e-postanıza iletilen formu doldurmanız gerekmektedir.', AppLanguage.en: 'Your request was sent to the department head. Please complete the form emailed to you to continue.', AppLanguage.ru: 'Заявка отправлена заведующему кафедрой. Заполните форму, отправленную вам по почте.'},
    'ca_title_screen': {AppLanguage.tr: 'Kendi Aktiviteni Oluştur', AppLanguage.en: 'Create Your Own Activity', AppLanguage.ru: 'Создай свою активность'},
    'ca_form_failed': {AppLanguage.tr: 'Form yüklenemedi. Bağlantını kontrol et.', AppLanguage.en: 'Could not load the form. Check your connection.', AppLanguage.ru: 'Не удалось загрузить форму. Проверьте соединение.'},
    'ca_title': {AppLanguage.tr: 'Başlık', AppLanguage.en: 'Title', AppLanguage.ru: 'Название'},
    'ca_place': {AppLanguage.tr: 'Mekân (sadece kayıtlı yerler)', AppLanguage.en: 'Place (registered places only)', AppLanguage.ru: 'Место (только зарегистрированные)'},
    'ca_time': {AppLanguage.tr: 'Saat (opsiyonel, örn. 18:00)', AppLanguage.en: 'Time (optional, e.g. 18:00)', AppLanguage.ru: 'Время (необязательно, напр. 18:00)'},
    'ca_place_busy': {AppLanguage.tr: 'Bu mekân o gün şu saatlerde dolu:', AppLanguage.en: 'This place is already booked that day at:', AppLanguage.ru: 'В этот день место занято в:'},
    'ca_head': {AppLanguage.tr: 'Bölüm başkanı (onaylayan)', AppLanguage.en: 'Department head (approver)', AppLanguage.ru: 'Заведующий кафедрой (утверждает)'},
    'ca_no_head': {AppLanguage.tr: 'Aktif bölüm başkanı bulunamadı.', AppLanguage.en: 'No active department head was found.', AppLanguage.ru: 'Активный заведующий кафедрой не найден.'},
    'ca_description': {AppLanguage.tr: 'Açıklama', AppLanguage.en: 'Description', AppLanguage.ru: 'Описание'},

    'bd_use_map': {AppLanguage.tr: 'Harita için Ana Sayfa veya Keşfet → Kampüs Haritası’nı kullan.', AppLanguage.en: 'For the map, use Home or Discover → Campus Map.', AppLanguage.ru: 'Для карты откройте Главная или Обзор → Карта кампуса.'},

    // Campus live map
    'clm_zones': {AppLanguage.tr: 'Bölgeler', AppLanguage.en: 'Zones', AppLanguage.ru: 'Зоны'},
    'clm_busy': {AppLanguage.tr: 'Yoğun', AppLanguage.en: 'Busy', AppLanguage.ru: 'Много людей'},
    'clm_all': {AppLanguage.tr: 'Tümü', AppLanguage.en: 'All', AppLanguage.ru: 'Все'},
    'clm_visibility': {AppLanguage.tr: 'Görünürlük', AppLanguage.en: 'Visibility', AppLanguage.ru: 'Видимость'},
    'clm_just_now': {AppLanguage.tr: 'Az önce', AppLanguage.en: 'Just now', AppLanguage.ru: 'Только что'},
    'clm_no_checkins': {AppLanguage.tr: 'Henüz check-in yok', AppLanguage.en: 'No check-ins yet', AppLanguage.ru: 'Отметок пока нет'},
    'clm_floor_plan': {AppLanguage.tr: 'Kat Planı', AppLanguage.en: 'Floor plan', AppLanguage.ru: 'План этажа'},
    'clm_workshop_status': {AppLanguage.tr: 'Atölye Durumu', AppLanguage.en: 'Workshop status', AppLanguage.ru: 'Статус мастерской'},
    'clm_no_equipment': {AppLanguage.tr: 'Ekipman bilgisi henüz eklenmedi', AppLanguage.en: 'No equipment information yet', AppLanguage.ru: 'Информации об оборудовании пока нет'},
    'clm_collab_board': {AppLanguage.tr: 'İş Birliği Panosu', AppLanguage.en: 'Collaboration board', AppLanguage.ru: 'Доска сотрудничества'},
    'clm_no_listings': {AppLanguage.tr: 'Henüz ilan yok', AppLanguage.en: 'No listings yet', AppLanguage.ru: 'Объявлений пока нет'},
    'clm_collab_hint': {AppLanguage.tr: 'Malzeme takası, model arama...', AppLanguage.en: 'Material swaps, looking for a model...', AppLanguage.ru: 'Обмен материалами, поиск модели...'},
    'clm_start_nav': {AppLanguage.tr: 'Navigasyonu Başlat', AppLanguage.en: 'Start navigation', AppLanguage.ru: 'Начать навигацию'},
    'clm_how_to_get_there': {AppLanguage.tr: 'Nasıl gitmek istersin?', AppLanguage.en: 'How do you want to get there?', AppLanguage.ru: 'Как вы хотите добраться?'},
    'clm_search_hint': {AppLanguage.tr: 'Bir yer ara (bina, atölye, kütüphane...)', AppLanguage.en: 'Search a place (building, workshop, library...)', AppLanguage.ru: 'Поиск места (здание, мастерская, библиотека...)'},
    'clm_no_results': {AppLanguage.tr: 'Sonuç bulunamadı', AppLanguage.en: 'No results found', AppLanguage.ru: 'Ничего не найдено'},

    // Group chat
    'cg_action_failed': {AppLanguage.tr: 'İşlem başarısız.', AppLanguage.en: 'That did not work.', AppLanguage.ru: 'Действие не выполнено.'},
    'cg_leave_q': {AppLanguage.tr: 'Gruptan çıkılsın mı?', AppLanguage.en: 'Leave this group?', AppLanguage.ru: 'Выйти из группы?'},
    'cg_leave': {AppLanguage.tr: 'Çık', AppLanguage.en: 'Leave', AppLanguage.ru: 'Выйти'},
    'cg_left': {AppLanguage.tr: 'Gruptan çıkıldı.', AppLanguage.en: 'You left the group.', AppLanguage.ru: 'Вы вышли из группы.'},
    'cg_leave_failed': {AppLanguage.tr: 'Gruptan çıkılamadı.', AppLanguage.en: 'Could not leave the group.', AppLanguage.ru: 'Не удалось выйти из группы.'},
    'cg_leave_group': {AppLanguage.tr: 'Gruptan çık', AppLanguage.en: 'Leave group', AppLanguage.ru: 'Выйти из группы'},

    // Profile / settings
    'pr_my_applications': {AppLanguage.tr: 'Başvurularım', AppLanguage.en: 'My applications', AppLanguage.ru: 'Мои заявки'},
    'pr_my_applications_sub': {AppLanguage.tr: 'Kulüp, etkinlik ve hizmet başvurularının durumu', AppLanguage.en: 'Status of your club, event and service applications', AppLanguage.ru: 'Статус заявок в клубы, на события и услуги'},
    'pr_my_appointments': {AppLanguage.tr: 'Randevularım', AppLanguage.en: 'My appointments', AppLanguage.ru: 'Мои записи'},
    'pr_my_appointments_sub': {AppLanguage.tr: 'Personel ile randevu al veya iptal et', AppLanguage.en: 'Book or cancel an appointment with staff', AppLanguage.ru: 'Запись к сотрудникам или её отмена'},
    'pr_location_visibility': {AppLanguage.tr: 'Konum görünürlüğüm', AppLanguage.en: 'My location visibility', AppLanguage.ru: 'Видимость моего местоположения'},
    'pr_admin_panel': {AppLanguage.tr: 'Yönetim paneli', AppLanguage.en: 'Admin panel', AppLanguage.ru: 'Панель администратора'},
    'pr_trainer_panel': {AppLanguage.tr: 'Eğitmen paneli', AppLanguage.en: 'Trainer panel', AppLanguage.ru: 'Панель преподавателя'},
    'pr_menu': {AppLanguage.tr: 'Menü', AppLanguage.en: 'Menu', AppLanguage.ru: 'Меню'},

    // Quests
    'qs_no_activity_year': {AppLanguage.tr: 'Bu yıl henüz kayıtlı bir aktivite yok.', AppLanguage.en: 'No recorded activity this year yet.', AppLanguage.ru: 'В этом году активности пока нет.'},
    'qs_score_note': {AppLanguage.tr: 'Skor XP’yi ölçer; bu ise bu yıl kampüsü nasıl kullandığını gösterir.', AppLanguage.en: 'The score measures XP; this shows how you used the campus this year.', AppLanguage.ru: 'Счёт измеряет XP; здесь — как вы пользовались кампусом в этом году.'},
    'qs_checkin_reward': {AppLanguage.tr: 'Check-in yaparsan +30 XP kazanırsın', AppLanguage.en: 'Check in to earn +30 XP', AppLanguage.ru: 'Отметьтесь и получите +30 XP'},
  };

  /// Read-only view of the table, so a test can assert that every key is
  /// translated into every language. Not for production code — `t()` is the
  /// only supported way to read a string.
  @visibleForTesting
  static Map<String, Map<AppLanguage, String>> get debugTable => _table;

  String t(String key) {
    final row = _table[key];
    if (row == null) return key;
    return row[language] ?? row[AppLanguage.en] ?? row[AppLanguage.tr] ?? key;
  }
}

/// Real translation for [PostCategory] labels — lives here (not on the enum
/// itself in `campus_models.dart`) since it needs an [AppStrings] instance
/// to pick a language. `.emoji` stays on the model as a plain getter since
/// emoji don't need translating.
extension PostCategoryLocalization on PostCategory {
  String label(AppStrings strings) => strings.t('post_type_$name');
}

class AppLocale extends InheritedWidget {
  final AppLanguage language;
  final AppStrings strings;

  AppLocale({super.key, required this.language, required super.child})
      : strings = AppStrings(language);

  static AppStrings of(BuildContext context) {
    final scope = context.dependOnInheritedWidgetOfExactType<AppLocale>();
    return scope?.strings ?? const AppStrings(AppLanguage.tr);
  }

  @override
  bool updateShouldNotify(AppLocale oldWidget) =>
      oldWidget.language != language;
}
