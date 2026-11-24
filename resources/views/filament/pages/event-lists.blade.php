<x-filament-panels::page>
    <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:end">
        <label class="text-sm">{{ __('reports.event') }}<br>
            <select wire:model.live="event" class="rounded-lg border-gray-300 dark:bg-white/5" style="max-width:100%">
                @foreach ($events as $id => $title)<option value="{{ $id }}">{{ $title }}</option>@endforeach
            </select>
        </label>
        <label class="text-sm">{{ __('lists.list') }}<br>
            <select wire:model.live="list" class="rounded-lg border-gray-300 dark:bg-white/5" style="max-width:100%">
                @foreach ($keys as $k)<option value="{{ $k }}">{{ __('lists.titles.'.$k) }}</option>@endforeach
            </select>
        </label>
        <label class="text-sm">{{ __('reports.role') }}<br>
            <select wire:model.live="role" class="rounded-lg border-gray-300 dark:bg-white/5" style="max-width:100%">
                <option value="">{{ __('reports.all_roles') }}</option>
                @foreach ($roleOptions as $r)<option value="{{ $r }}">{{ __('events.roles.'.$r) }}</option>@endforeach
            </select>
        </label>
    </div>

    @if ($model && $table)
        <x-filament::section :heading="$table->title.' ('.count($table->rows).')'" :description="$table->confidential ? __('lists.confidential_hint') : null">
            <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem">
                @foreach (\App\Support\Lists\ListExport::FORMATS as $format)
                    <x-filament::button tag="a" target="_blank" size="sm" color="gray" icon="heroicon-o-arrow-down-tray"
                        :href="route('admin.lists.export', ['event' => $model->id, 'key' => $key, 'format' => $format, 'role' => $role ?: null])">{{ strtoupper($format) }}</x-filament::button>
                @endforeach
                @if ($safety)
                    <x-filament::button tag="a" target="_blank" size="sm" color="warning" icon="heroicon-o-folder"
                        :href="route('admin.lists.folder', ['event' => $model->id])">{{ __('lists.folder') }}</x-filament::button>
                @endif
            </div>
            <div style="overflow-x:auto">
                <table class="w-full text-sm" style="border-collapse:collapse">
                    <thead><tr>@foreach ($table->headers as $h)<th style="text-align:left;padding:.4rem .6rem;border-bottom:1px solid #9ca3af;white-space:nowrap">{{ $h }}</th>@endforeach</tr></thead>
                    <tbody>
                    @forelse ($table->rows as $r => $row)
                        <tr>@foreach ($row as $c => $cell)<td style="padding:.4rem .6rem;border-bottom:1px solid #e5e7eb;vertical-align:top;white-space:pre-line;{{ $table->marked($r, $c) ? 'background:#fff2b3;color:#222;font-weight:600' : '' }}">{{ $cell }}</td>@endforeach</tr>
                    @empty
                        <tr><td colspan="{{ max(1, count($table->headers)) }}" style="padding:.6rem">{{ __('lists.empty') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if ($table->marks !== [])<p class="mt-2 text-sm"><span style="background:#fff2b3;color:#222;padding:0 .3rem">{{ __('lists.changed') }}</span></p>@endif
        </x-filament::section>
    @endif
</x-filament-panels::page>
