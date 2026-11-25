<?php

namespace Tests\Feature\Finance;

use App\Filament\Resources\ArchivedInvoices\Pages\ListArchivedInvoices;
use App\Models\ArchivedInvoice;
use App\Models\Person;
use App\Models\Role;
use App\Models\User;
use App\Support\Finance\InvoiceArchive;
use App\Support\Install\Defaults;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * Invoices from the system used before are kept as a read-only archive with their PDFs (MI07).
 */
class InvoiceArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        app(Defaults::class)->install('en');
        app()->setLocale('en');
    }

    private function user(string $role): User
    {
        auth()->forgetUser();
        $person = Person::factory()->create();
        $person->roleAssignments()->create(['role_id' => Role::query()->where('key', $role)->value('id')]);

        return User::query()->create(['name' => $role, 'email' => $role.'@example.org', 'password' => 'x', 'person_id' => $person->id]);
    }

    /** @return array{string, string} */
    private function files(): array
    {
        $csv = tempnam(sys_get_temp_dir(), 'inv').'.csv';
        file_put_contents($csv, "Nummer;Datum;Name;E-Mail;Betrag;Status;Bezahlt am;Notiz\n"
            ."R140;03.03.2025;Kim Ash;ash@example.org;\"1.250,50 €\";bezahlt;20.03.2025;Camp 2025\n"
            ."R141;2025-03-04;Robin Birch;;250;offen;;\n"
            ."2025-0142;04.03.2025;Sam Cedar;;90,00;storniert;;\n"
            .";05.03.2025;No number;;10;;;\n");
        $zipPath = tempnam(sys_get_temp_dir(), 'inv').'.zip';
        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('r140.pdf', '%PDF-1.4 invoice 140');
        $zip->addFromString('folder/2025_0142.PDF', '%PDF-1.4 invoice 142');
        $zip->addFromString('R999.pdf', '%PDF-1.4 unknown');
        $zip->addFromString('R141.pdf', 'not a pdf');
        $zip->close();

        return [$csv, $zipPath];
    }

    public function test_import_reads_the_table_and_the_pdfs_and_the_numbering_continues(): void
    {
        $person = Person::factory()->create();
        $person->contactPoints()->create(['type' => 'email', 'value' => 'ash@example.org', 'is_primary' => true]);
        Settings::set('invoice_next_number', '5');
        [$csv, $zip] = $this->files();

        $result = app(InvoiceArchive::class)->import($csv, 'invoices.csv', $zip);

        $this->assertSame([3, 0, 2, 143], [$result['imported'], $result['updated'], $result['pdfs'], $result['next_number']]);
        $this->assertCount(3, $result['skipped'], 'a row without a number, an unknown PDF and a file that is no PDF');
        $first = ArchivedInvoice::query()->where('number', 'R140')->firstOrFail();
        $this->assertSame([125050, 'paid', '2025-03-03', '2025-03-20', $person->id, 'Camp 2025'], [$first->total_cents, $first->status, $first->issued_on?->toDateString(), $first->paid_on?->toDateString(), $first->person_id, $first->note]);
        $this->assertSame(['open', 'cancelled'], [ArchivedInvoice::query()->where('number', 'R141')->value('status'), ArchivedInvoice::query()->where('number', '2025-0142')->value('status')]);
        $this->assertNotNull(ArchivedInvoice::query()->where('number', '2025-0142')->value('pdf_path'));
        $this->assertSame('143', Settings::get('invoice_next_number'));

        // Repeating the import changes nothing but the rows, and the numbering never goes back.
        Settings::set('invoice_next_number', '500');
        $again = app(InvoiceArchive::class)->import($csv, 'invoices.csv');
        $this->assertSame([0, 3, null], [$again['imported'], $again['updated'], $again['next_number']]);
        $this->assertSame(3, ArchivedInvoice::query()->count());
        $this->assertSame('500', Settings::get('invoice_next_number'));
    }

    public function test_finance_roles_see_the_archive_and_the_pdf_others_do_not(): void
    {
        $this->withoutMiddleware(AuthenticateSession::class);
        [$csv, $zip] = $this->files();
        app(InvoiceArchive::class)->import($csv, 'invoices.csv', $zip);
        $invoice = ArchivedInvoice::query()->where('number', 'R140')->firstOrFail();
        $session = ['two_factor_passed' => true];

        $treasurer = $this->user('treasurer');
        $this->actingAs($treasurer);
        Livewire::test(ListArchivedInvoices::class)->assertCanSeeTableRecords(ArchivedInvoice::query()->get())->assertActionVisible('import');
        $pdf = $this->actingAs($treasurer)->withSession($session)->get(route('admin.invoice-archive.pdf', $invoice))->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
        $this->actingAs($treasurer)->withSession($session)->get(route('admin.invoice-archive.pdf', ArchivedInvoice::query()->where('number', 'R141')->firstOrFail()))->assertNotFound();

        $safety = $this->user('safety_officer');
        $this->actingAs($safety)->withSession($session)->get(route('admin.invoice-archive.pdf', $invoice))->assertForbidden();
        $this->actingAs($safety)->withSession($session)->get('/admin/archived-invoices')->assertForbidden();
    }
}
