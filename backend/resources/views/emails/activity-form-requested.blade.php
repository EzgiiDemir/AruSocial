<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; color: #1C1E22;">
  <h2>Aktiviteniz oluşturuldu</h2>
  <p>Aktiviteniz oluşturulmuştur. Öncelikle e-posta adresinize gönderilen formu doldurmanız gerekmektedir.</p>
  <p><strong>{{ $event->title }}</strong></p>
  <p>
    <a href="{{ $formUrl }}" style="display:inline-block; padding:12px 20px; background:#5B4DFF; color:#fff; border-radius:8px; text-decoration:none;">
      Formu Doldur
    </a>
  </p>
  <p style="color:#6B7280; font-size:12px;">Bu bağlantı yalnızca bu aktivite için geçerlidir.</p>
  <hr>
  <p style="color:#6B7280; font-size:12px;">AruSocial — otomatik bildirim.</p>
</body>
</html>
