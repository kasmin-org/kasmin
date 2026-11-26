<?php

namespace App\Filament\Resources\MailTemplates;

use App\Support\Mail\TemplateCatalogue;
use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\EditorCommand;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Width;

/**
 * "Insert placeholder" and "Insert condition" for the template editor: both are chosen from lists, so nobody has to
 * know the names of the placeholders or the syntax of a condition (MT03).
 *
 * In the editor they are buttons of the toolbar and insert at the cursor. For the subject and for the HTML source they
 * are links next to the field and add at the end.
 */
class TemplateInserts
{
    public const PLACEHOLDER = 'insertPlaceholder';

    public const CONDITION = 'insertCondition';

    /** @return list<RichEditorTool> */
    public static function tools(): array
    {
        return [
            RichEditorTool::make(self::PLACEHOLDER)->label(__('mail.placeholders.insert'))->hiddenLabel(false)->icon('heroicon-o-variable')->activeStyling(false)->action(),
            RichEditorTool::make(self::CONDITION)->label(__('mail.placeholders.insert_condition'))->hiddenLabel(false)->icon('heroicon-o-arrows-right-left')->activeStyling(false)->action(),
        ];
    }

    /** @return list<Action> the dialogues behind the two buttons of the editor */
    public static function editorActions(): array
    {
        return [
            self::placeholderDialogue(self::PLACEHOLDER)->action(function (array $arguments, array $data, RichEditor $component): void {
                $component->runCommands([EditorCommand::make('insertContent', arguments: [e((string) $data['placeholder'])])], editorSelection: $arguments['editorSelection'] ?? null);
            }),
            self::conditionDialogue(self::CONDITION)->action(function (array $arguments, array $data, RichEditor $component): void {
                $component->runCommands([EditorCommand::make('insertContent', arguments: [e(self::conditionFrom($data))])], editorSelection: $arguments['editorSelection'] ?? null);
            }),
        ];
    }

    /**
     * Links next to a plain field (subject, HTML source): what is chosen is added at the end of the field.
     *
     * @return list<Action>
     */
    public static function fieldActions(string $field, bool $withCondition = true): array
    {
        $append = fn (string $text) => function (Get $get, Set $set) use ($field, $text): void {
            $set($field, rtrim((string) $get($field)).($get($field) ? ' ' : '').$text);
        };
        $actions = [
            self::placeholderDialogue($field.'Placeholder')->link()->icon('heroicon-o-variable')
                ->action(fn (array $data, Get $get, Set $set) => $append((string) $data['placeholder'])($get, $set)),
        ];
        if ($withCondition) {
            $actions[] = self::conditionDialogue($field.'Condition')->link()->icon('heroicon-o-arrows-right-left')
                ->action(fn (array $data, Get $get, Set $set) => $append(self::conditionFrom($data))($get, $set));
        }

        return $actions;
    }

    private static function placeholderDialogue(string $name): Action
    {
        return Action::make($name)->label(__('mail.placeholders.insert'))->modalHeading(__('mail.placeholders.insert'))->modalWidth(Width::Large)
            ->modalSubmitActionLabel(__('mail.placeholders.insert'))
            ->schema([
                Select::make('placeholder')->label(__('mail.placeholders.placeholder'))->options(fn () => TemplateCatalogue::placeholders())->searchable()->required(),
            ]);
    }

    private static function conditionDialogue(string $name): Action
    {
        return Action::make($name)->label(__('mail.placeholders.insert_condition'))->modalHeading(__('mail.placeholders.insert_condition'))->modalWidth(Width::TwoExtraLarge)
            ->modalDescription(__('mail.placeholders.condition_hint'))->modalSubmitActionLabel(__('mail.placeholders.insert_condition'))
            ->schema([
                Select::make('field')->label(__('mail.placeholders.field'))->options(fn () => TemplateCatalogue::fields())->searchable()->required(),
                Select::make('operator')->label(__('mail.placeholders.operator'))->options(fn () => array_combine(TemplateCatalogue::OPERATORS, array_map(fn (string $o) => (string) __('mail.placeholders.operators.'.$o), TemplateCatalogue::OPERATORS)))
                    ->default('filled')->required()->live()->selectablePlaceholder(false),
                TextInput::make('value')->label(__('mail.placeholders.value'))->maxLength(200)
                    ->visible(fn (Get $get) => in_array($get('operator'), TemplateCatalogue::NEEDS_VALUE, true))
                    ->required(fn (Get $get) => in_array($get('operator'), TemplateCatalogue::NEEDS_VALUE, true))
                    ->numeric(fn (Get $get) => in_array($get('operator'), ['greater', 'less'], true)),
                Textarea::make('then')->label(__('mail.placeholders.then'))->rows(3)->required()->maxLength(5000),
                Textarea::make('otherwise')->label(__('mail.placeholders.otherwise'))->rows(2)->maxLength(5000),
            ]);
    }

    /** @param  array<string, mixed>  $data */
    private static function conditionFrom(array $data): string
    {
        return TemplateCatalogue::condition((string) $data['field'], (string) $data['operator'], isset($data['value']) ? (string) $data['value'] : null, (string) $data['then'], isset($data['otherwise']) ? (string) $data['otherwise'] : null);
    }
}
