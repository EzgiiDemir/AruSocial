<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; color: #1C1E22;">
  <h2>Katılım formu dolduruldu — onayın bekleniyor</h2>
  <p><strong>{{ $student->name }}</strong>, <strong>{{ $event->title }}</strong> etkinliği için katılım formunu tamamladı.</p>
  @if($participationLabel)
    <p>Katılım türü: <strong>{{ $participationLabel }}</strong></p>
  @endif
  <p>Etkinlik saati: {{ $event->time }} · Yer: {{ $event->place_name }}</p>
  <p>Katılımı admin panelindeki Yoklama ekranından onaylayabilirsin.</p>
  <hr>
  <p style="color:#6B7280; font-size:12px;">AruSocial — otomatik bildirim.</p>
</body>
</html>
