# Gizlilik Politikası ve KVKK Aydınlatma Metni (taslak)

**ARUVERSE** — ARUCAD kampüs uygulaması

Bu metin hukuki inceleme bekleyen bir **taslaktır**. Yürürlüğe girmeden önce
ARUCAD hukuk müşavirliği / KVKK uyum danışmanı tarafından onaylanmalıdır.
Yayın adresi (domain kesinleşince): `/legal/privacy`

## Veri sorumlusu

Arkin University of Creative Arts and Design (ARUCAD), Girne / KKTC.

## Hangi veriler işlenir

- Kimlik ve iletişim: ad, `@arucad.edu.tr` e-posta, öğrenci numarası
- Oturum: Sanctum erişim jetonu, isteğe bağlı biyometrik kilit (cihazda)
- Konum: check-in ve harita için anlık GPS (kampüs geofence ile sınırlı)
- İçerik: gönderi, hikâye, sohbet, Ask ARUCAD geçmişi, medya
- Cihaz: push jetonu (FCM/APNs), dil ve görünürlük tercihleri
- Başvuru ve kariyer: form cevapları, CV dosyası (yüklendiğinde)

## Amaçlar

Kampüs sosyal ağı, etkinlik katılımı, check-in, yönlendirme, bildirim,
başvuru süreçleri ve güvenlik (moderasyon, ban, denetim kaydı).

## Hukuki dayanak

KVKK md. 5 — sözleşmenin ifası, meşru menfaat, açık rıza (konum, push,
biyometri ve isteğe bağlı profil alanları).

## Aktarımlar

Hizmetin çalışması için Microsoft Entra (kimlik), Firebase/FCM (bildirim),
Groq (Ask ARUCAD), OpenAI (görsel moderasyon, yapılandırıldığında),
Sentry (çökme raporları, DSN ile), e-posta SMTP sağlayıcısı. Anahtarlar
sunucuda tutulur; uygulama paketine gömülmez.

## Saklama

Hesap kapatılana veya yasal saklama süresi dolana kadar. Medya ve
başvuru dosyaları ilgili sürecin bitiminde silinebilir.

## Haklarınız

KVKK md. 11: erişim, düzeltme, silme, itiraz. İletişim:
`kvkk@arucad.edu.tr` (kurumsal adres kesinleşene kadar taslak).

## Çocuklar

Uygulama üniversite öğrencileri ve personeline yöneliktir.

Son güncelleme: 2026-09-01 (taslak)
