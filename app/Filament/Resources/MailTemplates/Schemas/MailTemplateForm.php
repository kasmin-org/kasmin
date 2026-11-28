<?php

namespace App\Filament\Resources\MailTemplates\Schemas;

use App\Filament\Resources\MailTemplates\TemplateInserts;
use App\Models\MailTemplate;
use App\Support\Language;
use App\Support\Mail\MailImages;
use App\Support\Mail\TemplateHtml;
use App\Support\Mail\TemplateRenderer;
use App\Support\Records\UniqueName;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Template editor (MT01–MT11): WYSIWYG per language, placeholders validated against the sandbox before saving.
 */
class MailTemplateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.mail.template'))->columnSpanFull()->columns(['md' => 2])->schema([
                Select::make('category')->label(__('admin.mail.category'))->options(array_combine(MailTemplate::CATEGORIES, array_map(fn ($c) => __('admin.mail.categories.'.$c), MailTemplate::CATEGORIES)))->required(),
                TextInput::make('name')->label(__('admin.form.name'))->required()->maxLength(200)->rule(fn (?MailTemplate $record) => new UniqueName(MailTemplate::query(), $record?->getKey())),
                Select::make('group')->label(__('admin.mail.group'))->options(fn () => array_combine(MailTemplate::GROUPS, array_map(fn (string $g) => __('admin.mail.groups.'.$g), MailTemplate::GROUPS)))
                    ->default('general')->required()->live()->selectablePlaceholder(false)
                    ->disabled(fn (?MailTemplate $record) => (bool) $record?->is_standard)->dehydrated(fn (?MailTemplate $record) => ! $record?->is_standard)
                    ->helperText(fn ($get, ?MailTemplate $record) => __('admin.mail.group_fields.'.($get('group') ?: 'general')).($record?->is_standard ? ' '.__('admin.mail.group_fixed') : '')),
                Select::make('audience')->label(__('admin.mail.audience'))->options(__('admin.mail.audiences'))->default('guardians')->required(),
                Toggle::make('active')->label(__('admin.form.active'))->default(true),
                TextInput::make('version')->label(__('admin.form.version'))->disabled()->dehydrated(false),
                FileUpload::make('attachments')->label(__('admin.mail.attachments'))->multiple()->disk('local')->directory('mail-attachments')->visibility('private')->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file) => Str::lower(Str::random(6)).'-'.Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.$file->guessExtension())->maxSize(5120)->maxFiles(5)
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->helperText(__('admin.mail.attachments_hint'))->columnSpanFull(),
            ]),
            Section::make(__('admin.mail.contents'))->columnSpanFull()->description(__('admin.mail.placeholder_help'))->schema([
                Repeater::make('contents')->relationship()->label(__('admin.mail.contents'))->hiddenLabel()->minItems(1)->maxItems(1)->defaultItems(1)->addable(false)->deletable(false)->reorderable(false)
                    ->mutateRelationshipDataBeforeFillUsing(fn (array $data) => ['html' => TemplateHtml::toEditor((string) ($data['html'] ?? ''))] + $data)
                    ->mutateRelationshipDataBeforeSaveUsing(fn (array $data) => self::clean($data))->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => self::clean($data))
                    ->schema([
                        Hidden::make('locale')->default(fn () => Language::current()), // a template has one text
                        TextInput::make('subject')->label(__('admin.mail.subject'))->required()->maxLength(250)->hintActions(TemplateInserts::fieldActions('subject', withCondition: false))
                            ->rule(fn ($livewire) => self::templateRule('subject', $livewire)),
                        RichEditor::make('html')->label(__('admin.mail.body'))->required()->visible(fn ($get) => ! $get('source'))->helperText(__('admin.mail.button_hint'))
                            ->tools(TemplateInserts::tools())->registerActions(TemplateInserts::editorActions())
                            ->toolbarButtons([[TemplateInserts::PLACEHOLDER, TemplateInserts::CONDITION], ['bold', 'italic', 'underline', 'link'], ['h2', 'h3'], ['bulletList', 'orderedList'], ['table', 'attachFiles'], ['undo', 'redo']])
                            ->fileAttachmentsAcceptedFileTypes(MailImages::TYPES)->fileAttachmentsMaxSize(2048)
                            ->saveUploadedFileAttachmentUsing(fn (TemporaryUploadedFile $file) => MailImages::store($file))
                            ->getFileAttachmentUrlUsing(fn (mixed $file) => is_string($file) ? MailImages::url($file) : null)
                            ->rule(fn ($livewire) => self::templateRule('html', $livewire)),
                        Textarea::make('html_source')->label(__('admin.mail.source'))->rows(16)->required()->visible(fn ($get) => (bool) $get('source'))->extraInputAttributes(['style' => 'font-family:monospace', 'spellcheck' => 'false'])->hintActions(TemplateInserts::fieldActions('html_source'))
                            ->rule(fn ($livewire) => self::templateRule('html', $livewire)),
                        Toggle::make('source')->label(__('admin.mail.source_view'))->live()->afterStateUpdated(function ($state, $get, $set) {
                            $state ? $set('html_source', TemplateHtml::fromEditor($get('html'))) : $set('html', TemplateHtml::toEditor((string) $get('html_source')));
                        }),
                        Textarea::make('text')->label(__('admin.mail.text'))->rows(4)->helperText(__('admin.mail.text_hint'))->rule(fn ($livewire) => self::templateRule('text', $livewire)),
                    ]),
            ]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function clean(array $data): array
    {
        if ($data['source'] ?? false) {
            $data['html'] = (string) ($data['html_source'] ?? '');
        } elseif (isset($data['html'])) {
            $data['html'] = TemplateHtml::fromEditor($data['html']);
        }
        unset($data['source'], $data['html_source']);

        return $data;
    }

    private static function templateRule(string $part, mixed $livewire = null): Closure
    {
        $group = TemplateInserts::group($livewire); // the placeholders of the chosen group count

        return function (string $attribute, mixed $value, Closure $fail) use ($part, $group) {
            $value = $part === 'html' ? TemplateHtml::fromEditor(is_array($value) ? $value : (string) $value) : $value;
            $errors = app(TemplateRenderer::class)->validate($part === 'subject' ? (string) $value : 'x', $part === 'html' ? (string) $value : 'x', $part === 'text' ? (string) $value : null, $group);
            if ($errors !== []) {
                // Everything that was found, so one round of corrections is enough. Saving stays blocked until then.
                $fail(__('admin.mail.template_error', ['error' => implode(' ', $errors)]));
            }
        };
    }
}
