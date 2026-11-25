@php($logo = (string) \App\Support\Settings::get('mail_logo'))
@php($colour = \App\Support\Mail\Composer::colour())
@php($footer = trim((string) \App\Support\Settings::get('mail_footer')))
<!DOCTYPE html>
<html lang="{{ $locale }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{{ $subject }}</title></head>
<body style="margin:0;padding:24px 12px;background:#f5f5f4;font-family:system-ui,-apple-system,'Segoe UI',sans-serif;color:#1c1c1c;">
<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;border-top:4px solid {{ $colour }};padding:24px;line-height:1.5;">
    @if ($logo !== '')<p style="margin:0 0 16px"><img src="{{ \App\Support\Mail\MailImages::url($logo) }}" alt="{{ $organisation }}" style="max-width:220px;max-height:80px;height:auto;border:0"></p>
    @else<p style="margin:0 0 16px;font-weight:600;">{{ $organisation }}</p>@endif
    {!! \App\Support\Mail\MailBody::START !!}{!! $body !!}{!! \App\Support\Mail\MailBody::END !!}
</div>
@if ($footer !== '')
<p style="max-width:560px;margin:16px auto 0;font-size:12px;color:#57534e;text-align:center;">{!! nl2br(e($footer)) !!}</p>
@endif
@if ($preferences)
<p style="max-width:560px;margin:16px auto 0;font-size:12px;color:#57534e;text-align:center;">
    {{ __('mail.footer_preferences', [], $locale) }}: <a href="{{ $preferences }}" style="color:#57534e;">{{ __('mail.footer_link', [], $locale) }}</a>
</p>
@endif
</body>
</html>
