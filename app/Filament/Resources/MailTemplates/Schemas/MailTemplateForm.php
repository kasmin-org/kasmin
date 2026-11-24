<?php

namespace App\Filament\Resources\MailTemplates\Schemas;

use App\Models\MailTemplate;
use App\Support\Mail\TemplateRenderer;
use Closure;
use Filament\Forms\Components\FileUpload;
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
            Section::make(__('admin.mail.template'))->columns(2)->schema([
                TextInput::make('key')->label(__('admin.form.key'))->required()->alphaDash()->unique(ignoreRecord: true),
                Select::make('category')->label(__('admin.mail.category'))->options(array_combine(MailTemplate::CATEGORIES, array_map(fn ($c) => __('admin.mail.categories.'.$c), MailTemplate::CATEGORIES)))->required(),
                TextInput::make('name.de')->label(__('admin.form.name').' (Deutsch)')->required(),
                TextInput::make('name.en')->label(__('admin.form.name').' (English)')->required(),
                Select::make('sender_profile_id')->label(__('admin.mail.sender_profile'))->relationship('senderProfile', 'name')
                    ->hintIcon('heroicon-m-question-mark-circle', tooltip: __('admin.mail.hints.sender_profile')),
                Select::make('audience')->label(__('admin.mail.audience'))->options(__('admin.mail.audiences'))->default('guardians')->required(),
                Toggle::make('active')->label(__('admin.form.active'))->default(true),
                TextInput::make('version')->label(__('admin.form.version'))->disabled()->dehydrated(false),
                FileUpload::make('attachments')->label(__('admin.mail.attachments'))->multiple()->disk('local')->directory('mail-attachments')->visibility('private')->getUploadedFileNameForStorageUsing(fn (TemporaryUploadedFile $file) => Str::lower(Str::random(6)).'-'.Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'.'.$file->guessExtension())->maxSize(5120)->maxFiles(5)
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])->helperText(__('admin.mail.attachments_hint'))->columnSpanFull(),
            ]),
            Section::make(__('admin.mail.contents'))->description(__('admin.mail.placeholder_help'))->schema([
                Repeater::make('contents')->relationship()->label('')->minItems(1)->maxItems(2)
                    ->itemLabel(fn (array $state) => strtoupper((string) ($state['locale'] ?? '')))
                    ->schema([
                        Select::make('locale')->label(__('admin.person.locale'))->options(['de' => 'Deutsch', 'en' => 'English'])->required()->distinct(),
                        TextInput::make('subject')->label(__('admin.mail.subject'))->required()->maxLength(250)
                            ->rule(fn () => self::templateRule('subject')),
                        RichEditor::make('html')->label(__('admin.mail.body'))->required()
                            ->toolbarButtons([['bold', 'italic', 'underline', 'link'], ['h2', 'h3'], ['bulletList', 'orderedList'], ['table'], ['undo', 'redo']])
                            ->rule(fn () => self::templateRule('html')),
                        Textarea::make('text')->label(__('admin.mail.text'))->rows(4)->helperText(__('admin.mail.text_hint')),
                    ]),
            ]),
        ]);
    }

    private static function templateRule(string $part): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($part) {
            $errors = app(TemplateRenderer::class)->validate($part === 'subject' ? (string) $value : 'x', $part === 'html' ? (string) $value : 'x', null);
            if ($errors !== []) {
                $fail(__('admin.mail.template_error', ['error' => $errors[0]]));
            }
        };
    }
}
