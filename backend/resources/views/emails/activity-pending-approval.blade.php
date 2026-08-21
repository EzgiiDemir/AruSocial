<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; color: #1C1E22;">
  <h2>Onayınız bekleniyor</h2>
  <p><strong>{{ $event->title }}</strong> aktivitesi formunu tamamladı ve değerlendirmenizi bekliyor.</p>
  <p>Etkinlik saati: {{ $event->time }} · Yer: {{ $event->place_name }}</p>
  @if($event->purpose)
    <p>Amaç: {{ $event->purpose }}</p>
  @endif
  <p>Admin panelindeki "Bekleyen Aktiviteler" ekranından inceleyip onaylayabilir veya reddedebilirsiniz.</p>
  <hr>
  <p style="color:#6B7280; font-size:12px;">AruSocial — otomatik bildirim.</p>
</body>
</html>
