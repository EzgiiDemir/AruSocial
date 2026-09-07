<p>Merhaba {{ $studentName }},</p>
<p><strong>{{ $activityTitle }}</strong> — durum: <strong>{{ $statusLabel }}</strong>.</p>
@if($note !== '')
<p>{{ $note }}</p>
@endif
@if($adminUrl !== '')
<p><a href="{{ $adminUrl }}" style="background:#000F9F;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none;display:inline-block;">Formu / paneli aç</a></p>
@endif
<p style="color:#6B7280;font-size:12px;">— AruSocial</p>
