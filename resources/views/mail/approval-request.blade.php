@php($items = array_slice($run->plan, 0, 20))
<p>{{ __('automations.mail.greeting', ['name' => $user->name], $locale) }}</p>
<p>{!! __('automations.mail.intro', ['name' => '<strong>'.e($run->automation?->label($locale)).'</strong>', 'count' => count($run->plan)], $locale) !!}
@if ($run->event) ({{ $run->event->title }})@endif</p>
<ul>
@foreach ($items as $item)
    <li>{{ $item['label'] }}@if (! empty($item['details'])) – {{ $item['details'] }}@endif</li>
@endforeach
</ul>
@if (count($run->plan) > count($items))
<p>{{ __('automations.mail.more', ['count' => count($run->plan) - count($items)], $locale) }}</p>
@endif
<p>
    <a href="{{ $url }}?decision=approve" style="display:inline-block;background:#1da029;color:#fff;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600;margin-right:8px;">{{ __('automations.mail.approve', [], $locale) }}</a>
    <a href="{{ $url }}?decision=deny" style="display:inline-block;background:#fff;color:#8a1c1c;padding:10px 18px;border-radius:8px;text-decoration:none;font-weight:600;border:1px solid #f3c2c2;">{{ __('automations.mail.deny', [], $locale) }}</a>
</p>
<p style="font-size:13px;color:#187f22;">{{ __('automations.mail.note', ['date' => $run->expires_at?->locale($locale)->isoFormat('LLL')], $locale) }}</p>
