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
    'nav_quests': {AppLanguage.tr: 'Aktivite', AppLanguage.en: 'Activity', AppLanguage.ru: 'Активность'},
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
    'home_today_events': {AppLanguage.tr: 'Bugün', AppLanguage.en: 'Today', AppLanguage.ru: 'Сегодня'},
    'home_all': {AppLanguage.tr: 'Tümü', AppLanguage.en: 'All', AppLanguage.ru: 'Все'},
    'home_no_events': {AppLanguage.tr: 'Bugün etkinlik yok', AppLanguage.en: 'No events today', AppLanguage.ru: 'Сегодня нет событий'},
    'home_for_you': {AppLanguage.tr: 'Sana Özel', AppLanguage.en: 'For You', AppLanguage.ru: 'Для тебя'},
    'home_score': {AppLanguage.tr: 'Aktivite', AppLanguage.en: 'Activity', AppLanguage.ru: 'Активность'},
    'home_go_there': {AppLanguage.tr: 'Oraya Git', AppLanguage.en: 'Go There', AppLanguage.ru: 'Перейти'},

    // Discover / Help
    'discover_creative': {AppLanguage.tr: 'Yaratıcı Kampüs', AppLanguage.en: 'Creative Campus', AppLanguage.ru: 'Творческий кампус'},
    'discover_clubs': {AppLanguage.tr: 'Kulüpler', AppLanguage.en: 'Clubs', AppLanguage.ru: 'Клубы'},
    'discover_sports': {AppLanguage.tr: 'Spor', AppLanguage.en: 'Sports', AppLanguage.ru: 'Спорт'},
    'discover_places': {AppLanguage.tr: 'Yerler', AppLanguage.en: 'Places', AppLanguage.ru: 'Места'},
    'discover_services': {AppLanguage.tr: 'Kampüs Hizmetleri', AppLanguage.en: 'Campus Services', AppLanguage.ru: 'Услуги кампуса'},
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

    // Quests / Leaderboard
    'quests_title': {AppLanguage.tr: 'Kampüs Sosyal Aktifliği', AppLanguage.en: 'Campus Social Activity', AppLanguage.ru: 'Социальная активность кампуса'},
    'quests_tagline': {AppLanguage.tr: 'Keşfet · katıl · check-in yap · XP kazan.', AppLanguage.en: 'Explore · join · check in · earn XP.', AppLanguage.ru: 'Исследуй · присоединяйся · отмечайся · получай XP.'},
    'quests_year_score': {AppLanguage.tr: 'YIL AKTİVİTESİ', AppLanguage.en: 'YEAR ACTIVITY', AppLanguage.ru: 'АКТИВНОСТЬ ГОДА'},
    'quests_leaderboard': {AppLanguage.tr: 'Liderlik Tablosu', AppLanguage.en: 'Leaderboard', AppLanguage.ru: 'Таблица лидеров'},
    'quests_suggestions': {AppLanguage.tr: 'Senin için öneriler', AppLanguage.en: 'Suggestions for you', AppLanguage.ru: 'Рекомендации для тебя'},

    // Place detail
    'place_checkin': {AppLanguage.tr: 'Check-in', AppLanguage.en: 'Check-in', AppLanguage.ru: 'Отметиться'},
    'place_navigate': {AppLanguage.tr: 'Yol Tarifi', AppLanguage.en: 'Navigate', AppLanguage.ru: 'Маршрут'},
    'place_tour': {AppLanguage.tr: '360° Keşfet', AppLanguage.en: '360° Explore', AppLanguage.ru: '360° тур'},
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
    'social_filter_following': {AppLanguage.tr: 'Takip ettiklerin', AppLanguage.en: 'Following', AppLanguage.ru: 'Подписки'},
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
    'notif_scope_banner': {AppLanguage.tr: 'Burada resmi ARUCAD duyuruları ve senin gerçek aktivite geçmişin var — başka öğrencilerin etkileşimleri gösterilmiyor, çünkü bu prototipte gerçek çok-kullanıcılı bir bildirim sistemi henüz yok.', AppLanguage.en: "This shows official ARUCAD announcements and your own real activity history — other students' interactions aren't shown, since this prototype doesn't have a real multi-user notification system yet.", AppLanguage.ru: 'Здесь показаны официальные объявления ARUCAD и твоя реальная история активности — действия других студентов не отображаются, так как в этом прототипе пока нет настоящей многопользовательской системы уведомлений.'},
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
  };

  String t(String key) => _table[key]?[language] ?? key;
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
