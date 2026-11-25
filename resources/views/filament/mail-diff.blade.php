<div class="text-sm">
    <p>{{ __('admin.mail.diff_hint') }}</p>
    @foreach ($diff as $locale => $lines)
        <h3 style="font-weight:600;margin-top:.75rem">{{ strtoupper($locale) }}</h3>
        <div style="font-family:monospace;overflow-wrap:anywhere">
            @foreach ($lines as [$sign, $line])<div style="{{ $sign === '-' ? 'background:#fde2e2;color:#7f1d1d' : ($sign === '+' ? 'background:#dcfce7;color:#14532d' : '') }};padding:.1rem .4rem;white-space:pre-wrap">{{ $sign === '=' ? ' ' : $sign }} {{ $line }}</div>@endforeach
        </div>
    @endforeach
</div>
