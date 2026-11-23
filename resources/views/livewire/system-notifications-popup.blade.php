<div>
    @foreach ($items as $n)
        <div wire:key="sn-{{ $n->id }}" role="alert" class="mx-4 mt-3 flex flex-wrap items-start justify-between gap-3 rounded-xl p-4 text-sm shadow-sm"
             style="{{ $n->level === 'danger' ? 'background:#fde2e2;color:#8a1c1c' : ($n->level === 'warning' ? 'background:#fdf0d5;color:#7a4b00' : 'background:#dcf1dd;color:#14501c') }}">
            <div class="min-w-0">
                <p class="font-semibold">{{ $n->text('title') }}</p>
                <p>{{ $n->text('message') }}</p>
                @if ($n->url)<a class="underline" href="{{ $n->url }}" target="_blank" rel="noopener">{{ __('system.status.more') }}</a>@endif
            </div>
            <button type="button" wire:click="dismiss({{ $n->id }})" class="rounded-lg border px-3 py-1 font-medium">{{ __('system.status.dismiss') }}</button>
        </div>
    @endforeach
</div>
