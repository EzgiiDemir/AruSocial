<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Form Gönderildi</title>
  <style>
    body { font-family: -apple-system, "Segoe UI", sans-serif; background: #F5F5F7; margin: 0; padding: 24px; color: #1C1E22; }
    .card { max-width: 480px; margin: 60px auto; background: #fff; border-radius: 16px; padding: 32px; text-align: center; box-shadow: 0 2px 12px rgba(0,0,0,.06); }
    h1 { font-size: 20px; }
    p { color: #4B5563; }
  </style>
</head>
<body>
  <div class="card">
    <h1>✅ Formunuz gönderildi</h1>
    <p>Formunuz gönderildi. Aktiviteniz ilgili yetkili tarafından değerlendirilecektir.</p>
    <p><strong>{{ $event->title }}</strong></p>
  </div>
</body>
</html>
