<p>{{ __('automations.mail.greeting', ['name' => $user->name], $locale) }}</p>
<p>{{ trans_choice('automations.mail.summary_intro', $new->count() + $old->count(), ['count' => $new->count() + $old->count()], $locale) }}</p>
@foreach ([['runs' => $new, 'title' => null], ['runs' => $old, 'title' => __('automations.mail.summary_older', [], $locale)]] as $group)
    @if ($group['runs']->isNotEmpty())
        @if ($group['title'])<p style="font-weight:600;margin-top:18px;">{{ $group['title'] }}</p>@endif
        @foreach ($group['runs'] as $run)
            @php($items = array_slice($run->plan ?? [], 0, 8))
            <div style="border:1px solid #d7ead9;border-radius:8px;padding:12px 14px;margin:10px 0;">
                <p style="margin:0 0 6px;"><strong>{{ $run->automation?->label($locale) }}</strong>@if ($run->event) ({{ $run->event->title }})@endif – {{ trans_choice('automations.mail.summary_actions', count($run->plan ?? []), ['count' => count($run->plan ?? [])], $locale) }}</p>
                <ul style="margin:0 0 8px;">
                @foreach ($items as $item)
                    <li>{{ $item['label'] }}@if (! empty($item['details'])) – {{ $item['details'] }}@endif</li>
                @endforeach
                </ul>
                @if (count($run->plan ?? []) > count($items))<p style="margin:0 0 8px;">{{ __('automations.mail.more', ['count' => count($run->plan) - count($items)], $locale) }}</p>@endif
                <p style="margin:0;">
                    <a href="{{ $url($run) }}?decision=approve" style="display:inline-block;background:#1da029;color:#fff;padding:8px 14px;border-radius:8px;text-decoration:none;font-weight:600;margin-right:8px;">{{ __('automations.mail.approve', [], $locale) }}</a>
                    <a href="{{ $url($run) }}?decision=deny" style="display:inline-block;background:#fff;color:#8a1c1c;padding:8px 14px;border-radius:8px;text-decoration:none;font-weight:600;border:1px solid #f3c2c2;">{{ __('automations.mail.deny', [], $locale) }}</a>
                    <span style="font-size:13px;color:#187f22;margin-left:6px;">{{ __('automations.mail.summary_until', ['date' => $run->expires_at?->locale($locale)->isoFormat('L')], $locale) }}</span>
                </p>
            </div>
        @endforeach
    @endif
@endforeach
<p style="font-size:13px;color:#187f22;">{{ __('automations.mail.summary_note', [], $locale) }}</p>
