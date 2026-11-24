<x-filament-panels::page>
    <x-filament::section :heading="__('privacy.requests')" :description="__('privacy.requests_hint')">
        @forelse ($requests as $r)
            <div style="display:flex;flex-wrap:wrap;justify-content:space-between;gap:.5rem;padding:.4rem 0" class="border-t border-gray-200 dark:border-white/10">
                <span>{{ $r->fullName() }}</span>
                <x-filament::button size="sm" color="gray" wire:click="review({{ $r->id }})">{{ __('privacy.review') }}</x-filament::button>
            </div>
        @empty
            <p class="text-sm">{{ __('privacy.no_requests') }}</p>
        @endforelse
    </x-filament::section>

    @if ($person && $plan)
        <x-filament::section :heading="__('privacy.plan_for', ['name' => $person->fullName()])">
            <h4 class="text-sm font-semibold">{{ __('privacy.will_delete') }}</h4>
            <ul class="mb-3 text-sm">
                @foreach ($plan['delete'] as $what => $count)<li>{{ __('privacy.items.'.$what) }}: {{ $count }}</li>@endforeach
            </ul>
            <h4 class="text-sm font-semibold">{{ __('privacy.will_keep') }}</h4>
            <ul class="mb-3 text-sm">
                @foreach ($plan['keep'] as $what => $k)<li>{{ __('privacy.items.'.$what) }}: {{ $k['count'] }} – {{ $k['reason'] }}</li>@endforeach
            </ul>
            <label class="block text-sm">{{ __('privacy.reason') }}
                <input type="text" wire:model="reason" maxlength="200" class="mt-1 block w-full rounded-lg border-gray-300 dark:bg-white/5">
            </label>
            <div class="mt-3">
                <x-filament::button color="danger" wire:click="anonymise" wire:confirm="{{ __('privacy.confirm') }}">{{ __('privacy.anonymise') }}</x-filament::button>
            </div>
        </x-filament::section>
    @endif

    <x-filament::section :heading="__('privacy.retention')" :description="__('privacy.retention_hint', ['years' => $years])">
        @forelse ($retention as $entry)
            <div class="border-t border-gray-200 py-1 text-sm dark:border-white/10">{{ $entry['person']->fullName() }} – {{ trans_choice('privacy.answers', $entry['responses'], ['count' => $entry['responses']]) }} ({{ __('privacy.since') }} {{ \Illuminate\Support\Carbon::parse($entry['since'])->isoFormat('LL') }})</div>
        @empty
            <p class="text-sm">{{ __('privacy.retention_none') }}</p>
        @endforelse
        @if ($retention !== [])
            <div class="mt-3"><x-filament::button color="danger" wire:click="runRetention" wire:confirm="{{ __('privacy.retention_confirm') }}">{{ __('privacy.retention_run') }}</x-filament::button></div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
