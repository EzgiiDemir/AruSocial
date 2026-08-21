<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Aktivite Formu — {{ $event->title }}</title>
  <style>
    body { font-family: -apple-system, "Segoe UI", sans-serif; background: #F5F5F7; margin: 0; padding: 24px; color: #1C1E22; }
    .card { max-width: 560px; margin: 0 auto; background: #fff; border-radius: 16px; padding: 28px; box-shadow: 0 2px 12px rgba(0,0,0,.06); }
    h1 { font-size: 20px; margin: 0 0 4px; }
    .sub { color: #6B7280; font-size: 13px; margin-bottom: 20px; }
    label { display: block; font-weight: 700; font-size: 13px; margin: 16px 0 6px; }
    input, textarea, select { width: 100%; padding: 10px 12px; border: 1px solid #D1D5DB; border-radius: 8px; font-size: 14px; box-sizing: border-box; font-family: inherit; }
    textarea { min-height: 90px; resize: vertical; }
    button { margin-top: 24px; width: 100%; padding: 13px; background: #5B4DFF; color: #fff; border: none; border-radius: 10px; font-size: 15px; font-weight: 700; cursor: pointer; }
    .required { color: #E24C4C; }
    .error { color: #E24C4C; font-size: 12px; margin-top: 4px; }
  </style>
</head>
<body>
  <div class="card">
    <h1>{{ $event->title }}</h1>
    <p class="sub">Aktivitenizin değerlendirmeye gönderilmesi için lütfen aşağıdaki formu doldurun.</p>

    @if ($errors->any())
      <div class="error">
        <ul>
          @foreach ($errors->all() as $error)
            <li>{{ $error }}</li>
          @endforeach
        </ul>
      </div>
    @endif

    <form method="POST" action="{{ url()->full() }}" enctype="multipart/form-data">
      @csrf
      <label>Öğrenci Numarası <span class="required">*</span></label>
      <input type="text" name="studentNumber" required value="{{ old('studentNumber') }}">

      <label>Telefon <span class="required">*</span></label>
      <input type="tel" name="phone" required value="{{ old('phone') }}">

      <label>Fakülte <span class="required">*</span></label>
      <input type="text" name="faculty" required value="{{ old('faculty') }}">

      <label>Bölüm <span class="required">*</span></label>
      <input type="text" name="department" required value="{{ old('department') }}">

      <label>Bitiş Saati</label>
      <input type="text" name="endTime" placeholder="Örn. 18:00" value="{{ old('endTime') }}">

      <label>Tahmini Katılımcı Sayısı</label>
      <input type="number" name="estimatedAttendees" min="1" value="{{ old('estimatedAttendees') }}">

      <label>Hedef Kitle</label>
      <input type="text" name="targetAudience" placeholder="Örn. Tüm öğrenciler" value="{{ old('targetAudience') }}">

      <label>Amaç <span class="required">*</span></label>
      <textarea name="purpose" required>{{ old('purpose') }}</textarea>

      <label>Gereksinimler</label>
      <textarea name="requirements">{{ old('requirements') }}</textarea>

      <label>Görsel / Afiş</label>
      <input type="file" name="poster" accept="image/*">

      <button type="submit">Formu Gönder</button>
    </form>
  </div>
</body>
</html>
