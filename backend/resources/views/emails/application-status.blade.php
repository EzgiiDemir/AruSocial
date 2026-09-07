<p>Merhaba {{ $recipientName }},</p>
<p><strong>{{ $targetLabel }}</strong> — durum: <strong>{{ $statusLabel }}</strong>.</p>
@if($note !== '')
<p>{{ $note }}</p>
@endif
@if($appUrl !== '')
<p><a href="{{ $appUrl }}" style="background:#000F9F;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none;display:inline-block;">Detaylı formu aç</a></p>
@endif
<p style="color:#6B7280;font-size:12px;">— AruSocial</p>
