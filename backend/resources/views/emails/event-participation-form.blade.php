<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; color: #1C1E22;">
  <h2>Katılım formu</h2>
  <p><strong>{{ $event->title }}</strong> etkinliğine katılımını tamamlamak için son bir adım kaldı.</p>
  @if($formUrl)
    <p><a href="{{ $formUrl }}" style="background:#2B4C7E; color:white; padding:10px 18px; border-radius:8px; text-decoration:none;">Formu Doldur</a></p>
  @else
    <p>Bu etkinlik için henüz bir form bağlanmamış — uygulama içinden katılımını doğrudan onaylayabilirsin.</p>
  @endif
  <hr>
  <p style="color:#6B7280; font-size:12px;">AruSocial — otomatik bildirim.</p>
</body>
</html>
