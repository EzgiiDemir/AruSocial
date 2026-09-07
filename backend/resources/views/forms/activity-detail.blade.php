<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Aktivite detay formu</title>
  <style>
    body { font-family: system-ui, sans-serif; max-width: 560px; margin: 40px auto; padding: 0 16px; color: #1C1E22; }
    label { display: block; margin-top: 12px; font-weight: 600; font-size: 14px; }
    input, textarea { width: 100%; padding: 10px; margin-top: 4px; border: 1px solid #E9EBEF; border-radius: 8px; }
    button { margin-top: 18px; background: #000F9F; color: #fff; border: 0; padding: 12px 18px; border-radius: 10px; font-weight: 700; }
    .muted { color: #6B7280; font-size: 13px; }
    .ok { background: #F3F4F6; padding: 14px; border-radius: 10px; }
    .err { background: #F3F4F6; padding: 14px; border-radius: 10px; }
  </style>
</head>
<body>
  <h1>Aktivite detay formu</h1>
  <p class="muted">{{ $event->title }} · {{ $event->place_name }}</p>

  @if(!$valid)
    <div class="err">Bu form linki geçersiz veya süresi dolmuş. Lütfen e-postanızdaki güncel linki kullanın.</div>
  @elseif($submitted)
    <div class="ok">Formunuz alındı. Bölüm başkanı incelemesine iletildi.</div>
  @else
    <form method="post">
      @csrf
      <input type="hidden" name="token" value="{{ $token }}">
      <label>Telefon *</label>
      <input name="phone" required>
      <label>Acil durum iletişim</label>
      <input name="emergency">
      <label>Ekipman / ihtiyaç</label>
      <input name="equipment">
      <label>Ek notlar</label>
      <textarea name="notes" rows="4"></textarea>
      <button type="submit">Gönder</button>
    </form>
  @endif
</body>
</html>
