# Student Social Network Moderation Assistant

Türkçe, İngilizce ve Rusça içerikleri yüksek hassasiyetle kontrol eden Flutter + Node.js başlangıç paketi.

## Kurulum

```bash
cd backend
cp .env.example .env
# .env içine OPENAI_API_KEY yaz
npm install
npm start
```

Flutter projesine `flutter/lib/services/content_moderation_service.dart` dosyasını kopyalayın ve `http` paketini ekleyin.

```dart
final moderation = ContentModerationService(baseUrl: 'http://10.0.2.2:3000');
final result = await moderation.checkText(postText);
if (!result.allowed) {
  throw Exception('Bu içerik topluluk kurallarına uygun görünmüyor.');
}
```

## API

`POST /api/moderate-text`

```json
{ "text": "Kontrol edilecek gönderi" }
```

Servis `allowed`, `reasons`, `scores` ve desteklenen dilleri döndürür.

## Güvenlik

- API anahtarını Flutter içine koymayın.
- Moderasyonu veritabanına yazmadan önce backend'de çalıştırın.
- Servis hata verirse içerik güvenli varsayılan olarak engellenir.
- Kelime listesini kendi topluluğunuzun kullanım biçimlerine göre genişletin.
- Görsel moderasyonu ayrıca ekleyin; bu paket metin moderasyonudur.
