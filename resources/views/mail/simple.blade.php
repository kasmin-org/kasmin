<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<body style="font-family: system-ui, sans-serif; color: #14501C; background: #f3faf3; padding: 24px;">
<div style="max-width: 480px; margin: 0 auto; background: #fff; border-radius: 12px; padding: 24px;">
    <p>{{ $text }}</p>
    @if ($url)<p><a href="{{ $url }}" style="display: inline-block; background: #1DA029; color: #fff; padding: 12px 20px; border-radius: 8px; text-decoration: none; font-weight: 600;">{{ $button }}</a></p>@endif
    <p style="font-size: 13px; color: #187f22;">{{ $note }}</p>
</div>
</body>
</html>
