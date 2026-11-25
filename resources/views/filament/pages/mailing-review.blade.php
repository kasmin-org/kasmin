<x-filament-panels::page>
    <div @if (in_array($mailing->status, ['sending', 'paused', 'waiting'], true)) wire:poll.10s @endif>
        <x-filament::section :heading="__('admin.mail.mailing_status.'.$mailing->status)">
            @if ($total > 0)
                <progress max="{{ $total }}" value="{{ $done }}" style="width:100%;height:1rem" aria-label="{{ __('admin.mail.progress') }}">{{ $done }}/{{ $total }}</progress>
                <p class="text-sm">{{ __('admin.mail.progress_of', ['done' => $done, 'total' => $total]) }}@foreach ($counts as $status => $number) · {{ __('admin.mail.statuses.'.$status) }}: {{ $number }}@endforeach</p>
            @else
                <p class="text-sm">{{ __('admin.mail.not_prepared') }}</p>
            @endif
            @if ($mayEdit)
                <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.75rem">
                    @if ($mailing->status === 'sending')<x-filament::button size="sm" color="warning" icon="heroicon-o-pause" wire:click="pause">{{ __('admin.mail.pause') }}</x-filament::button>@endif
                    @if ($mailing->status === 'paused')<x-filament::button size="sm" color="success" icon="heroicon-o-play" wire:click="resume">{{ __('admin.mail.resume') }}</x-filament::button>@endif
                    @if (in_array($mailing->status, ['sending', 'paused', 'prepared', 'waiting'], true))<x-filament::button size="sm" color="danger" icon="heroicon-o-x-mark" wire:click="cancel" wire:confirm="{{ __('admin.mail.cancel_confirm') }}">{{ __('admin.mail.cancel') }}</x-filament::button>@endif
                </div>
            @endif
        </x-filament::section>
    </div>
    @if ($mail)
        <x-filament::section :heading="__('admin.mail.mail_of', ['n' => $position + 1, 'total' => $total])">
            <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:.75rem">
                <x-filament::button size="sm" color="gray" icon="heroicon-o-chevron-left" wire:click="go(-1)" :disabled="$position === 0">{{ __('admin.mail.previous') }}</x-filament::button>
                <x-filament::button size="sm" color="gray" icon="heroicon-o-chevron-right" wire:click="go(1)" :disabled="$position >= $total - 1">{{ __('admin.mail.next') }}</x-filament::button>
            </div>
            <p class="text-sm" style="overflow-wrap:anywhere"><strong>{{ __('admin.mail.to') }}:</strong> {{ $mail->to_name }} &lt;{{ $mail->to_address }}&gt;<br><strong>{{ __('admin.mail.subject') }}:</strong> {{ $mail->subject }}<br><strong>{{ __('admin.event.status') }}:</strong> {{ __('admin.mail.statuses.'.$mail->status) }}@if ($attachments !== [])<br><strong>{{ __('admin.mail.attachments') }}:</strong> {{ implode(', ', $attachments) }}@endif</p>
            <div style="margin-top:.75rem" wire:key="mail-{{ $mail->id }}">{{ $frame }}</div>
        </x-filament::section>
    @endif
    @if ($excluded !== [])
        <x-filament::section :heading="__('admin.mail.excluded', ['count' => count($excluded)])">
            <ul class="text-sm">@foreach ($excluded as $e)<li>{{ $e['person']->fullName() }} – {{ __('admin.mail.exclusions.'.$e['reason']) }}</li>@endforeach</ul>
        </x-filament::section>
    @endif
</x-filament-panels::page>
