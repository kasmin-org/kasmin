<?php

namespace App\Filament\Resources\MailTemplates\Tables;

use App\Models\MailTemplate;
use App\Support\Mail\Placeholders;
use App\Support\Mail\TemplateRenderer;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

class MailTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('admin.form.name'))->state(fn (MailTemplate $r) => $r->label())->searchable(['key']),
            TextColumn::make('category')->label(__('admin.mail.category'))->formatStateUsing(fn ($state) => __('admin.mail.categories.'.$state))->badge(),
            TextColumn::make('version')->label(__('admin.form.version')),
            IconColumn::make('is_standard')->label(__('admin.mail.standard'))->boolean(),
        ])->filters([
            SelectFilter::make('category')->label(__('admin.mail.category'))->options(array_combine(MailTemplate::CATEGORIES, array_map(fn ($c) => __('admin.mail.categories.'.$c), MailTemplate::CATEGORIES))),
        ])->recordActions([
            Action::make('preview')->label(__('admin.mail.preview'))->icon('heroicon-o-eye')->modalSubmitAction(false)
                ->modalContent(function (MailTemplate $record) {
                    $html = '';
                    foreach ($record->contents as $content) {
                        $r = app(TemplateRenderer::class)->renderStrings($content->subject, $content->html, $content->text, Placeholders::sample());
                        $html .= '<h3 style="font-weight:600">'.e(strtoupper($content->locale)).': '.e($r['subject']).'</h3><div style="border:1px solid #ddd;padding:12px;margin:8px 0 16px;border-radius:8px">'.$r['html'].'</div>';
                    }

                    return new HtmlString($html);
                }),
            EditAction::make(),
        ]);
    }
}
