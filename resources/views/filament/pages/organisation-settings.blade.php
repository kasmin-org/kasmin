<x-filament-panels::page>
    {{-- Search in the settings (S01): hides fields and sections that do not match; nothing is sent to the server. --}}
    <div x-data="{ q: '' }" x-effect="
        const needle = q.trim().toLowerCase();
        $root.querySelectorAll('.fi-section').forEach((section) => {
            const heading = (section.querySelector('.fi-section-header')?.innerText || '').toLowerCase();
            const whole = needle === '' || heading.includes(needle);
            let any = whole;
            section.querySelectorAll('.fi-fo-field').forEach((field) => {
                const hit = whole || field.innerText.toLowerCase().includes(needle);
                field.closest('[class*=fi-grid-col]')?.style.setProperty('display', hit ? '' : 'none');
                field.style.display = hit ? '' : 'none';
                any = any || hit;
            });
            section.style.display = any ? '' : 'none';
        });
    " class="space-y-6">
        <input type="search" x-model.debounce.150ms="q" data-settings-search placeholder="{{ __('admin.settings.search') }}" aria-label="{{ __('admin.settings.search') }}"
               style="display:block;width:100%;max-width:28rem;border:1px solid rgba(128,128,128,.45);border-radius:.5rem;padding:.5rem .75rem;font-size:.875rem;background:transparent">
        <form wire:submit="save" class="space-y-6">
            {{ $this->form }}
            <x-filament::button type="submit">{{ __('admin.settings.save') }}</x-filament::button>
        </form>
    </div>
</x-filament-panels::page>
