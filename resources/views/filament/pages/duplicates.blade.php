<x-filament-panels::page>
    @forelse ($pairs as $pair)
        <x-filament::section>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,260px),1fr));gap:1rem" class="text-sm">
                @foreach (['first', 'second'] as $key)
                    <div>
                        <a class="font-semibold underline" href="{{ url('/admin/people/'.$pair[$key]->id) }}">{{ $pair[$key]->fullName() }}</a>
                        <p>{{ $pair[$key]->birth_date?->isoFormat('L') ?? '–' }}</p>
                        <p>{{ $pair[$key]->contactPoints->pluck('value')->implode(', ') }}</p>
                    </div>
                @endforeach
            </div>
            <p class="mt-2 text-sm">{{ __('people.reasons.'.$pair['reason']) }}</p>
            <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.75rem">
                {{ ($this->mergeAction)(['first' => $pair['first']->id, 'second' => $pair['second']->id]) }}
                {{ ($this->dismissAction)(['first' => $pair['first']->id, 'second' => $pair['second']->id]) }}
            </div>
        </x-filament::section>
    @empty
        <p>{{ __('people.no_duplicates') }}</p>
    @endforelse
    <x-filament-actions::modals />
</x-filament-panels::page>
