<?php

namespace App\Filament\Resources\AutomationRuns\Pages;

use App\Filament\Resources\AutomationRuns\AutomationRunResource;
use App\Models\AutomationRun;
use App\Models\User;
use App\Support\Automations\Engine;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

/**
 * Decision in the approvals inbox: approve all, approve with changes (untick items) or deny with a comment (AT06).
 */
class ViewAutomationRun extends ViewRecord
{
    protected static string $resource = AutomationRunResource::class;

    private function run(): AutomationRun
    {
        /** @var AutomationRun $run */
        $run = $this->getRecord();

        return $run;
    }

    private function canDecide(): bool
    {
        $user = auth()->user();
        $run = $this->run();

        return $user instanceof User && $run->isPending() && $run->automation !== null && app(Engine::class)->mayApprove($run->automation, $user);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')->label(__('automations.approve'))->icon('heroicon-o-check')->color('success')
                ->visible(fn () => $this->canDecide())
                ->schema([
                    CheckboxList::make('included')->label(__('automations.planned'))->bulkToggleable()
                        ->options(fn () => collect($this->run()->plan)->mapWithKeys(fn ($i) => [$i['key'] => $i['label']])->all())
                        ->default(fn () => array_column($this->run()->plan, 'key')),
                    Textarea::make('comment')->label(__('automations.comment'))->maxLength(490),
                ])
                ->modalSubmitActionLabel(__('automations.approve_selected'))
                ->action(function (array $data) {
                    $excluded = array_values(array_diff(array_column($this->run()->plan, 'key'), (array) ($data['included'] ?? [])));
                    $this->decide(true, $excluded, $data['comment'] ?? null);
                }),
            Action::make('deny')->label(__('automations.deny'))->icon('heroicon-o-x-mark')->color('danger')
                ->visible(fn () => $this->canDecide())
                ->schema([Textarea::make('comment')->label(__('automations.comment'))->maxLength(490)])
                ->action(fn (array $data) => $this->decide(false, [], $data['comment'] ?? null)),
        ];
    }

    /** @param  list<string>  $excluded */
    private function decide(bool $approve, array $excluded, ?string $comment): void
    {
        /** @var User $user */
        $user = auth()->user();
        try {
            $run = app(Engine::class)->decide($this->run(), $user, $approve, $excluded, $comment);
            Notification::make()->title(__('automations.statuses.'.$run->status))->success()->send();
        } catch (\RuntimeException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
        $this->redirect(AutomationRunResource::getUrl('view', ['record' => $this->run()]));
    }
}
