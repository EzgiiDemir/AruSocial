<!DOCTYPE html>
<html lang="tr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Detaylı Başvuru Formu — ARUCAD</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; max-width: 640px; margin: 0 auto; padding: 32px 20px 60px; color: #1C1E22; background: #F7F8FA; }
    h1 { font-size: 22px; margin-bottom: 4px; }
    .muted { color: #6B7280; font-size: 13px; }
    .card { background: #fff; border-radius: 14px; padding: 20px; margin-top: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.06); }
    label { display: block; margin-top: 16px; font-weight: 600; font-size: 14px; }
    .help { color: #6B7280; font-size: 12px; margin-top: 2px; }
    input[type=text], input[type=number], input[type=date], textarea, select {
      width: 100%; padding: 10px 12px; margin-top: 6px; border: 1px solid #E5E7EB; border-radius: 8px; font-size: 14px; font-family: inherit;
    }
    textarea { min-height: 90px; resize: vertical; }
    .choice-row { display: flex; align-items: center; gap: 8px; margin-top: 6px; font-weight: 400; }
    .choice-row input { width: auto; margin: 0; }
    button { margin-top: 22px; width: 100%; background: #000F9F; color: #fff; border: 0; padding: 13px 18px; border-radius: 10px; font-weight: 700; font-size: 15px; cursor: pointer; }
    button:hover { background: #000a70; }
    .ok { background: #F3F4F6; color: #2A7C13; padding: 16px; border-radius: 10px; }
    .err { background: #F3F4F6; color: #EA0029; padding: 12px 14px; border-radius: 10px; margin-top: 14px; font-size: 13.5px; }
    .note { background: #FDE021; color: #1C1E22; padding: 14px; border-radius: 10px; margin-top: 16px; font-size: 13.5px; }
  </style>
</head>
<body>
  <h1>Detaylı Başvuru Formu</h1>
  @if($valid)
    <p class="muted">{{ $targetLabel }} @if($student) · {{ $student->name }} @endif</p>
  @endif

  @if(!$valid)
    <div class="card">
      <div class="err">Bu form linki geçersiz veya süresi dolmuş. Lütfen e-postanızdaki en güncel linki kullanın.</div>
    </div>
  @elseif($state === 'approved')
    <div class="card"><div class="ok">Başvurunuz onaylandı — artık gerçek anlamda katılımcısınız. Bu form artık düzenlenemez.</div></div>
  @elseif($state === 'rejected')
    <div class="card"><div class="err">Bu başvuru reddedilmiştir. Yeni bir başvuru yapmak için uygulamayı kullanabilirsiniz.</div></div>
  @elseif($state === 'submitted')
    <div class="card"><div class="ok">Detaylı formunuz alındı. Başvurunuz ilgili yetkili tarafından inceleniyor — sonuç e-posta ve uygulama içi bildirimle iletilecek.</div></div>
  @else
    <div class="card">
      @if(!empty($reviewNote))
        <div class="note"><strong>Revizyon notu:</strong> {{ $reviewNote }}</div>
      @endif
      @if(!empty($error))
        <div class="err">{{ $error }}</div>
      @endif
      <form method="post">
        @csrf
        @forelse($questions as $question)
          <label>{{ $question->label }}@if($question->required) *@endif</label>
          @if($question->help_text)
            <div class="help">{{ $question->help_text }}</div>
          @endif
          @php $value = $answers[$question->id] ?? null; @endphp
          @switch($question->type)
            @case('textarea')
              <textarea name="q_{{ $question->id }}" @if($question->required) required @endif>{{ $value }}</textarea>
              @break
            @case('number')
              <input type="number" name="q_{{ $question->id }}" value="{{ $value }}" @if($question->required) required @endif>
              @break
            @case('date')
              <input type="date" name="q_{{ $question->id }}" value="{{ $value }}" @if($question->required) required @endif>
              @break
            @case('dropdown')
              <select name="q_{{ $question->id }}" @if($question->required) required @endif>
                <option value="">— Seçiniz —</option>
                @foreach(($question->options ?? []) as $opt)
                  <option value="{{ $opt }}" @selected($value === $opt)>{{ $opt }}</option>
                @endforeach
              </select>
              @break
            @case('single_choice')
              @foreach(($question->options ?? []) as $opt)
                <div class="choice-row">
                  <input type="radio" name="q_{{ $question->id }}" value="{{ $opt }}" @checked($value === $opt) @if($question->required) required @endif>
                  <span>{{ $opt }}</span>
                </div>
              @endforeach
              @break
            @case('checkbox')
              <div class="choice-row">
                <input type="checkbox" name="q_{{ $question->id }}" value="1" @checked($value)>
                <span>Onaylıyorum</span>
              </div>
              @break
            @default
              <input type="text" name="q_{{ $question->id }}" value="{{ $value }}" @if($question->required) required @endif>
          @endswitch
        @empty
          <p class="muted">Bu başvuru için ek soru tanımlanmamış — doğrudan gönderebilirsiniz.</p>
        @endforelse
        <button type="submit">Gönder</button>
      </form>
    </div>
  @endif
</body>
</html>
