<?php

/**
 * Admin ve Trainer panellerinin kendi etiketleri.
 *
 * Filament kendi arayüzünü (düğmeler, filtreler, sayfalama, doğrulama)
 * zaten çeviriyor; burada yalnızca bu projenin yazdığı metinler var.
 */
return [

    'groups' => [
        'campus' => 'Kampüs',
        'people' => 'Kişiler',
        'content' => 'İçerik',
        'settings' => 'Ayarlar',
    ],

    'dashboard' => [
        'quick_actions' => 'Hızlı işlemler',
        'new' => 'Yeni :thing',
        'needs_attention' => 'İlgi bekleyenler',
        'on_campus' => 'Kampüste şu an',
        'my_department' => 'Bölümüm',
    ],

    'common' => [
        'deleted_at' => 'Silindi',
        'purge' => 'Kalıcı olarak silinsin mi?',
        'purge_body' => 'Bu kaydı geri dönüşü olmadan siler. Uygulamadan kaldırmak için normal silme yeterlidir ve o geri alınabilir.',
        'purge_bulk' => 'Bunlar kalıcı olarak silinsin mi?',
        'responsible_staff' => 'Sorumlu personel',
        'responsible_staff_help' => 'Öğrencinin sorusu olduğunda yönlendirileceği kişi.',
    ],

    'places' => [
        'section' => 'Mekân',
        'category_help' => 'Keşfet ekranında mekânı gruplandırır.',
        'street' => 'Sokak / adres',
        'map' => 'Harita üzerinde',
        'map_help' => 'İşaretin konumu ve girişin nasıl yapıldığı.',
        'latitude' => 'Enlem',
        'longitude' => 'Boylam',
        'accessible' => 'Basamaksız erişim',
        'accessible_help' => 'Bunu filtreleyen öğrencilere gösterilir; iyimser bir yanıt birini kullanamayacağı bir kapıya yönlendirir.',
        'distance' => 'Yürüme mesafesi',
        'distance_help' => 'Serbest metin, örn. "3 dk".',
        'tour' => '360° tur',
        'tour_help' => 'Kampüs rehberi senkronizasyonu doldurur; yalnızca hatalıysa değiştirin.',
        'tour_url' => 'Tur adresi',
        'tour_target' => 'Tur hedefi',
        'tour_target_help' => 'Turun açılacağı sahne.',
        'media' => 'Medya',
        'cover_url' => 'Kapak görseli adresi',
        'has_tour' => '360° turu var',
    ],

    'clubs' => [
        'section' => 'Kulüp',
        'members' => 'Üyeler',
    ],

    'sports' => [
        'section' => 'Spor',
        'facility_help' => 'Nerede yapıldığı.',
        'contact_help' => 'Telefon, e-posta veya bir isim — serbest metin.',
    ],

    'services' => [
        'section' => 'Hizmet',
        'hours' => 'Çalışma saatleri',
        'where' => 'Nerede bulunur',
        'contact_person' => 'İletişim kişisi',
        'contact_help' => 'Telefon veya e-posta.',
        'topics' => 'Konular',
        'topics_help' => 'Öğrencinin hangi konuda gelebileceği. Etiket olarak gösterilir.',
        'add_topic' => 'Konu ekle',
    ],

    'food' => [
        'section' => 'İşletme',
        'hours' => 'Çalışma saatleri',
        'standing_menu' => 'Sabit menü',
        'menu_file' => 'Menü dosyası adresi',
        'daily_menus' => 'Günlük menüler',
        'has_menu_file' => 'Menü dosyası',
    ],

    'shuttle' => [
        'section' => 'Güzergâh',
        'colour' => 'Renk',
        'colour_help' => 'Güzergâhın uygulamada nasıl çizileceği.',
        'order' => 'Sıra',
        'order_help' => 'Küçük sayılar önce görünür.',
        'stops' => 'Duraklar',
        'stops_help' => 'Servisin uğradığı sırayla.',
        'add_stop' => 'Durak ekle',
        'times' => 'Saatler',
        'times_help' => 'Tarifede yazdığı gibi serbest metin — örn. 08:30.',
        'departures' => 'Gidiş',
        'returns' => 'Dönüş',
        'add_departure' => 'Gidiş saati ekle',
        'add_return' => 'Dönüş saati ekle',
    ],

    'career' => [
        'section' => 'İlan',
        'kind' => 'Tür',
        'kind_help' => 'Staj, tam zamanlı, yarı zamanlı…',
        'work_type' => 'Çalışma şekli',
        'url' => 'Başvuru bağlantısı',
        'dates' => 'Tarihler ve görünürlük',
        'posted' => 'Yayınlandı',
        'published_help' => 'Yayınlanmamış ilanlar öğrencilere görünmez.',
        'details' => 'Ayrıntılar',
        'extra_info' => 'Diğer bilgiler',
        'expired' => 'Son başvuru tarihi geçti',
    ],

    'events' => [
        'section' => 'Etkinlik',
        'date' => 'Tarih',
        'time_help' => 'Görünmesi gerektiği gibi, örn. 18:00 - 20:00.',
        'where' => 'Nerede',
        'campus_place' => 'Kampüs mekânı',
        'campus_place_help' => 'Etkinliği haritaya bağlar.',
        'place_name' => 'Mekân adı',
        'who' => 'Kimler',
        'organizer_email' => 'Düzenleyen e-postası',
        'academic_year' => 'Akademik yıl',
        'xp_help' => 'Katılan öğrenciye verilir.',
        'publishing' => 'Yayınlama',
        'publishing_help' => 'Bir etkinlik yalnızca yayınlandığında, taslak olmadığında ve yayın aralığı içindeyken kampüs takviminde görünür.',
        'draft_help' => 'Taslaklar öğrencilere görünmez.',
        'publish_at' => 'Yayın tarihi',
        'publish_at_help' => 'Hemen yayınlamak için boş bırakın.',
        'expires_at' => 'Bitiş tarihi',
        'expires_at_help' => 'Listede kalması için boş bırakın.',
        'review_note' => 'İnceleme notu',
        'status' => 'Durum',
        'on_calendar' => 'Kampüs takviminde',
        'upcoming' => 'Yaklaşan',
        'workflow' => [
            'draft' => 'Taslak',
            'pending' => 'İnceleme bekliyor',
            'published' => 'Yayında',
            'rejected' => 'Reddedildi',
        ],
    ],

    'media' => [
        'preview' => 'Önizleme',
        'file' => 'Dosya',
        'uploaded_by' => 'Yükleyen',
        'uploaded_at' => 'Yüklendi',
        'size' => 'Boyut',
        'status' => 'Durum',
        'used_in' => 'Kullanıldığı yer',
        'unused' => 'Hiçbir yerde kullanılmıyor',
        'unused_only' => 'Yalnızca kullanılmayanlar',
        'missing_file' => 'Dosya eksik',
        'unknown_type' => 'Önizlenebilir bir dosya değil',
        'approved' => 'Onaylandı',
        'pending' => 'Bekliyor',
        'rejected' => 'Reddedildi',
        'purge' => 'Kalıcı olarak sil',
        'purge_bulk' => 'Bunlar kalıcı olarak silinsin mi?',
        'purge_body' => 'Bu, dosyayı depolamadan siler. Geri alınamaz ve yedeği tutulmaz.',
        'purge_confirm' => 'Geri alınamayacağını anlıyorum',
        'delete_unused' => 'Bu dosyayı hiçbir şey kullanmıyor. Sonradan geri alınabilir.',
        'delete_used' => 'Bu dosya :count öğede kullanılıyor: :list. Oralarda boş bir kare görünecek. Sonradan geri alınabilir.',
    ],

    'translations' => [
        'nav' => 'Çeviriler',
        'missing_badge' => 'En az bir dilde yayınlanmış çevirisi olmayan metinler',
        'missing' => 'Eksik',
        'unpublished' => 'Yayınlanmamış değişiklik',
        'publish' => 'Yayınla',
        'publish_heading' => 'Bu taslaklar yayınlansın mı?',
        'publish_body' => 'Her telefon bir sonraki kontrolünde bunu alır. Uygulama güncellemesi gerekmez — ve önceki bir sürüme dönmek dışında geri alma yoktur.',
        'published_none' => 'Yayınlanacak bir şey yok',
        'published_count' => ':count çeviri yayınlandı',
        'delete_body' => 'Uygulama bu anahtarları adıyla çağırır. Birini silmek, yüklü her sürümde o metnin yerinde hiçbir şey görünmemesi demektir.',
    ],

];
