<?php

namespace App\Filament\Support;

use App\Filament\Pages;
use App\Filament\Resources;
use Filament\Pages\Page;
use Filament\Resources\Pages\Page as ResourcePage;
use Filament\Resources\Resource;

/**
 * The five areas of the admin interface (B11): People, Events, Communication, Accounting and Administration. The bar
 * at the top switches the area, the navigation on the left shows only the pages of the chosen area. This is the one
 * place where every page is put into an area, a group within it and an order.
 */
final class Areas
{
    public const AREAS = [
        'people' => 'heroicon-o-users',
        'events' => 'heroicon-o-calendar-days',
        'communication' => 'heroicon-o-envelope',
        'finance' => 'heroicon-o-banknotes',
        'administration' => 'heroicon-o-cog-6-tooth',
    ];

    /** Groups within the areas, in this order after the pages without a group. */
    public const GROUPS = ['people_care', 'forms', 'automations', 'event_templates', 'finance_settings', 'system'];

    /** @var array<class-string, array{0: string, 1: string|null, 2: int}> class => [area, group, sort] */
    public const ITEMS = [
        Resources\People\PersonResource::class => ['people', null, 10],
        Resources\Households\HouseholdResource::class => ['people', null, 20],
        Resources\Groups\GroupResource::class => ['people', null, 30],
        Resources\MembershipTypes\MembershipTypeResource::class => ['people', null, 40],
        Pages\DuplicatesPage::class => ['people', 'people_care', 10],
        Pages\ImportPeoplePage::class => ['people', 'people_care', 20],
        Resources\ImportBatches\ImportBatchResource::class => ['people', 'people_care', 30],

        Resources\Events\EventResource::class => ['events', null, 10],
        Resources\EventSeries\EventSeriesResource::class => ['events', null, 20],
        Pages\SeasonWizardPage::class => ['events', null, 30],
        Pages\EventListsPage::class => ['events', null, 40],
        Pages\StatisticsPage::class => ['events', null, 50],
        Resources\Forms\FormResource::class => ['events', 'forms', 10],
        Resources\PublicForms\PublicFormResource::class => ['events', 'forms', 20],
        Resources\PublicSubmissions\PublicSubmissionResource::class => ['events', 'forms', 30],
        Resources\Automations\AutomationResource::class => ['events', 'automations', 10],
        Resources\AutomationRuns\AutomationRunResource::class => ['events', 'automations', 20],
        Resources\EventTemplates\EventTemplateResource::class => ['events', 'event_templates', 10],

        Resources\Mailings\MailingResource::class => ['communication', null, 10],
        Resources\MailTemplates\MailTemplateResource::class => ['communication', null, 20],
        Resources\OutgoingMails\OutgoingMailResource::class => ['communication', null, 30],
        Resources\BadAddresses\BadAddressResource::class => ['communication', null, 40],
        Resources\Consents\ConsentResource::class => ['communication', null, 50],

        Resources\Invoices\InvoiceResource::class => ['finance', null, 10],
        Resources\Payments\PaymentResource::class => ['finance', null, 20],
        Resources\Refunds\RefundResource::class => ['finance', null, 30],
        Resources\ArchivedInvoices\ArchivedInvoiceResource::class => ['finance', null, 40],
        Resources\JournalEntries\JournalEntryResource::class => ['finance', null, 50],
        Pages\MoneyAccountsPage::class => ['finance', null, 70],
        Pages\AccountingReportsPage::class => ['finance', null, 80],
        Resources\Accounts\AccountResource::class => ['finance', 'finance_settings', 10],
        Resources\CostCenters\CostCenterResource::class => ['finance', 'finance_settings', 20],
        Resources\Funds\FundResource::class => ['finance', 'finance_settings', 30],
        Resources\Periods\PeriodResource::class => ['finance', 'finance_settings', 40],
        Pages\InvoiceSettingsPage::class => ['finance', 'finance_settings', 50],

        Pages\GlobalSettingsPage::class => ['administration', null, 10],
        Pages\OrganisationSettingsPage::class => ['administration', null, 20],
        Resources\Roles\RoleResource::class => ['administration', null, 30],
        Resources\MailAccounts\MailAccountResource::class => ['administration', null, 40],
        Pages\PrivacyPage::class => ['administration', null, 50],
        Resources\AuditEvents\AuditEventResource::class => ['administration', null, 60],
        Resources\ApiTokens\ApiTokenResource::class => ['administration', null, 70],
        Resources\Webhooks\WebhookResource::class => ['administration', null, 80],
        Pages\SystemStatusPage::class => ['administration', 'system', 10],
        Pages\BackupsPage::class => ['administration', 'system', 20],
        Pages\UpdatePage::class => ['administration', 'system', 30],
    ];

    /** The area of a page or resource, or null (dashboard, user menu). */
    public static function of(string $class): ?string
    {
        return self::ITEMS[$class][0] ?? null;
    }

    public static function group(string $class): ?string
    {
        $group = self::ITEMS[$class][1] ?? null;

        return $group === null ? null : (string) __('admin.nav.'.$group);
    }

    public static function sort(string $class): ?int
    {
        return self::ITEMS[$class][2] ?? null;
    }

    /** The area of the page that is open now. */
    public static function current(): ?string
    {
        $class = request()->route()?->getControllerClass();
        if (! is_string($class) || ! class_exists($class)) {
            return null;
        }
        if (is_subclass_of($class, ResourcePage::class)) {
            $class = $class::getResource();
        }

        return self::of($class);
    }

    /** Whether the person may open the page or resource. */
    public static function allowed(string $class): bool
    {
        if (is_subclass_of($class, Resource::class) || is_subclass_of($class, Page::class)) {
            return $class::canAccess();
        }

        return false;
    }

    /**
     * The areas the person can open, each with its first page.
     *
     * @return array<string, array{label: string, icon: string, url: string, active: bool}>
     */
    public static function visible(): array
    {
        $current = self::current();
        $areas = [];
        foreach (self::AREAS as $area => $icon) {
            $first = collect(self::ITEMS)->filter(fn (array $item) => $item[0] === $area)
                ->sortBy(fn (array $item) => [array_search($item[1], [null, ...self::GROUPS], true), $item[2]])
                ->keys()->first(fn (string $class) => self::allowed($class));
            if ($first !== null) {
                $areas[$area] = ['label' => (string) __('admin.areas.'.$area), 'icon' => $icon, 'url' => $first::getUrl(), 'active' => $current === $area];
            }
        }

        return $areas;
    }
}
