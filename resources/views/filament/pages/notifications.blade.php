<x-filament-panels::page>
    <x-filament::section :heading="__('mail.notifications_by_mail')" :description="__('mail.notifications_hint')">
        <form wire:submit="save">
            <div style="display:grid;gap:.75rem">
                @foreach ($topics as $topic)
                    <label class="text-sm" style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:.5rem">
                        <span>{{ __('mail.topics.'.$topic) }}</span>
                        <select wire:model="choices.{{ $topic }}" class="rounded-lg border-gray-300 dark:bg-white/5" style="border-width:1px;padding:.35rem .6rem">
                            @foreach (\App\Models\NotificationSubscription::FREQUENCIES as $f)<option value="{{ $f }}">{{ __('mail.frequencies.'.$f) }}</option>@endforeach
                        </select>
                    </label>
                @endforeach
            </div>
            <x-filament::button type="submit" style="margin-top:1rem">{{ __('portal.save') }}</x-filament::button>
        </form>
    </x-filament::section>
    <x-filament::section :heading="__('mail.notifications_latest')">
        <ul class="text-sm">
            @forelse ($latest as $event)
                <li style="padding:.4rem 0;border-bottom:1px solid #e5e7eb;overflow-wrap:anywhere"><span style="opacity:.7">{{ $event->created_at->isoFormat('L LT') }} · {{ __('mail.topics.'.$event->topic) }}</span><br>
                    @if ($mayOpen($event->url))<a class="underline" href="{{ $event->url }}">{{ $event->summary }}</a>@else{{ $event->summary }}@endif</li>
            @empty
                <li>{{ __('lists.empty') }}</li>
            @endforelse
        </ul>
    </x-filament::section>
</x-filament-panels::page>
