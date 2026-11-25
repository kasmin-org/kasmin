@if ($url)
    <a href="{{ $url }}" target="_blank" rel="noopener" data-help-link title="{{ __('admin.help.title') }}"
       style="display:inline-flex;align-items:center;gap:.3rem;margin-top:.25rem;font-size:.8rem;color:#4b5563;text-decoration:none">
        <span aria-hidden="true" style="display:inline-flex;align-items:center;justify-content:center;width:1.15rem;height:1.15rem;border-radius:9999px;border:1px solid currentColor;font-weight:700;font-size:.72rem">?</span>
        <span>{{ __('admin.help.link') }}</span>
    </a>
@endif
