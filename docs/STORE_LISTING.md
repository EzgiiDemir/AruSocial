# App Store / Play Store listing (taslak)

Paket kimliği (Android `applicationId` / iOS bundle): `com.arucad.arucadCampusPrototype`
OAuth redirect scheme (Entra, paket kimliğinden bağımsız): `com.example.arucad_campus_prototype://oauthredirect`

Bu metinler mağaza hesabı açılınca konsola yapıştırılır. Ekran görüntüleri
ve feature graphic gerçek cihaz / Figma export ile üretilmelidir — bu
repoda hazır görsel seti yoktur.

## Kısa açıklama (80 karakter)

ARUCAD kampüs hayatı: harita, etkinlik, sosyal, Ask ARUCAD.

## Uzun açıklama (TR)

ARUVERSE, Arkin University of Creative Arts and Design öğrencileri ve
personeli için kampüs uygulamasıdır.

- Kampüs haritası ve yürüme tarifleri
- Etkinlikler, kulüpler, check-in
- Sosyal akış ve sohbet
- Kariyer ve başvuru
- Ask ARUCAD kampüs asistanı
- Bildirimler (Firebase yapılandırıldığında)

Giriş yalnızca `@arucad.edu.tr` hesaplarıyla. Gizlilik:
{APP_URL}/legal/privacy — Kullanım şartları: {APP_URL}/legal/terms

## Long description (EN)

ARUVERSE is the campus app for ARUCAD students and staff: map, events,
social feed, career applications, and Ask ARUCAD. Sign-in is limited to
@arucad.edu.tr accounts.

Privacy: {APP_URL}/legal/privacy
Terms: {APP_URL}/legal/terms

## Data safety / App Privacy (özet)

- Konum: check-in ve harita (zorunlu değil; reddedilirse özellik kısıtlanır)
- Kişisel bilgi: ad, e-posta
- Kullanıcı içeriği: gönderi, medya, sohbet
- Tanılama: Sentry (DSN yapılandırıldığında)
- Analitik: hesap bağlanana kadar kapalı (taxonomy kodda hazır)

## Feature graphic / ekran görüntüleri

Play: 1024×500 feature graphic, telefon 16:9 ekran görüntüleri.
App Store: 6.7" ve 5.5" zorunlu set. Bunlar bu milestonda üretilmez;
gerçek cihaz + mağaza hesabı gerekir.
