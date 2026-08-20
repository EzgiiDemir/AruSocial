<!DOCTYPE html>
<html>
<body style="font-family: sans-serif; color: #1C1E22;">
  <h2>Yeni katılım talebi</h2>
  <p><strong>{{ $student->name }}</strong>, <strong>{{ $event->title }}</strong> etkinliğine katılmak istiyor.</p>
  @if($participationLabel)
    <p>Katılım türü: <strong>{{ $participationLabel }}</strong></p>
  @endif
  <p>Etkinlik saati: {{ $event->time }} · Yer: {{ $event->place_name }}</p>
  <p>Bu talebi admin panelinden görüntüleyebilirsin.</p>
  <hr>
  <p style="color:#6B7280; font-size:12px;">AruSocial — otomatik bildirim.</p>
</body>
</html>
