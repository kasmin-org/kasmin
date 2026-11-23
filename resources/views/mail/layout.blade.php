<!DOCTYPE html>
<html lang="{{ $locale }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $subject }}</title></head>
<body style="margin:0;padding:24px 12px;background:#f3faf3;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;color:#14501c;">
<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;padding:24px;line-height:1.5;">
    <p style="margin:0 0 16px;font-weight:600;color:#1da029;">{{ $organisation }}</p>
    {!! $body !!}
</div>
@if ($preferences)
<p style="max-width:560px;margin:16px auto 0;font-size:12px;color:#187f22;text-align:center;">
    {{ __('mail.footer_preferences', [], $locale) }}: <a href="{{ $preferences }}" style="color:#187f22;">{{ __('mail.footer_link', [], $locale) }}</a>
</p>
@endif
</body>
</html>
