<?php

namespace App\Filament\Resources\Mailings\RelationManagers;

use App\Models\OutgoingMail;
use App\Support\Mail\Composer;
use App\Support\Mail\MailBody;
use App\Support\Mail\MailLinks;
use App\Support\Mail\PreviewFrame;
use App\Support\Mail\TemplateHtml;
use App\Support\Mail\TemplateRenderer;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Single mails of a mailing: page through them and edit them before sending (MM05, MM06, TP06).
 */
class MailsRelationManager extends RelationManager
{
    protected static string $relationship = 'mails';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.mail.mails');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('subject')->label(__('admin.mail.subject'))->required()->columnSpanFull(),
            RichEditor::make('html')->label(__('admin.mail.body'))->required()->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('to_name')->label(__('admin.mail.to'))->description(fn (OutgoingMail $r) => $r->to_address)->searchable(['to_name', 'to_address']),
            TextColumn::make('subject')->label(__('admin.mail.subject'))->limit(50),
            TextColumn::make('status')->label(__('admin.event.status'))->formatStateUsing(fn ($state) => __('admin.mail.statuses.'.$state))->badge(),
            IconColumn::make('edited')->label(__('admin.mail.edited'))->boolean(),
        ])->recordActions([
            ViewAction::make()->schema([])->modalContent(fn (OutgoingMail $r) => PreviewFrame::make(MailLinks::preview($r->html, '#'), $r->subject, 520)),
            EditAction::make()->visible(fn (OutgoingMail $r) => $r->status === 'held')
                ->mutateRecordDataUsing(fn (array $data) => ['html' => TemplateHtml::toEditor(MailBody::get((string) $data['html']))] + $data)
                ->mutateDataUsing(function (array $data, OutgoingMail $record): array {
                    $body = TemplateHtml::buttons(TemplateHtml::fromEditor($data['html']), Composer::colour());
                    $footer = strstr($record->text, "\n\n-- \n") ?: '';

                    return ['html' => MailBody::set($record->html, $body), 'text' => TemplateRenderer::toText($body).$footer, 'edited' => true] + $data;
                }),
        ]);
    }
}
