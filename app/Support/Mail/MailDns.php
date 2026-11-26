<?php

namespace App\Support\Mail;

use App\Models\MailAccount;
use App\Models\SenderProfile;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Crypto\DkimSigner;

/**
 * DNS check of the sender domains (ST02): SPF, DKIM and DMARC with concrete suggestions; warning if DMARC is not
 * "p=reject"; for direct delivery whether PHP can sign with DKIM.
 */
class MailDns
{
    /** Common selectors that are tried when none is configured (relays sign with their own selector). */
    private const COMMON_SELECTORS = ['default', 'kas', 'dkim', 'mail', 'selector1', 'selector2', 'k1', 'google', 's1', 's2'];

    /** @var (callable(string, int): array<int, array<string, mixed>>)|null */
    private $resolver;

    /** @param (callable(string, int): array<int, array<string, mixed>>)|null $resolver for tests */
    public function __construct(?callable $resolver = null)
    {
        $this->resolver = $resolver;
    }

    /**
     * @return list<array{domain: string, account: string, direct: bool, checks: list<array{record: string, status: string, found: ?string, suggestion: ?string, detail: string}>}>
     */
    public function report(): array
    {
        $rows = [];
        $sender = SenderProfile::main();
        $pairs = $sender === null ? [] : [[Str::lower(Str::after($sender->from_address, '@')), MailAccount::main()]];
        $default = (string) config('mail.from.address');
        if ($default !== '' && str_contains($default, '@')) {
            $pairs[] = [Str::lower(Str::after($default, '@')), MailAccount::main()];
        }
        $seen = [];
        foreach ($pairs as [$domain, $account]) {
            $key = $domain.'|'.($account->id ?? 0);
            if ($domain === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $rows[] = $this->check($domain, $account);
        }

        return $rows;
    }

    /**
     * @return array{domain: string, account: string, direct: bool, checks: list<array{record: string, status: string, found: ?string, suggestion: ?string, detail: string}>}
     */
    public function check(string $domain, ?MailAccount $account): array
    {
        $direct = $account?->isDirect() ?? false;
        $checks = [];

        // SPF
        $spf = $this->txt($domain, fn ($v) => str_starts_with(strtolower($v), 'v=spf1'));
        $sender = $direct ? 'a:'.(gethostname() ?: 'your-server') : ($account?->smtp_host ? 'a:'.$account->smtp_host : 'mx');
        $checks[] = [
            'record' => 'SPF', 'status' => $spf ? (str_contains($spf, '-all') || str_contains($spf, '~all') ? 'ok' : 'warning') : 'error',
            'found' => $spf, 'suggestion' => $spf ? null : "{$domain}. TXT \"v=spf1 ".($sender === 'mx' ? 'mx' : 'mx '.$sender).' -all"',
            'detail' => $spf ? '' : __('system.dns.spf_missing'),
        ];

        // DKIM
        $selectors = array_filter(array_unique(array_merge([$account?->dkim_selector], $direct ? [] : self::COMMON_SELECTORS)));
        $dkim = null;
        $dkimSelector = null;
        foreach ($selectors as $selector) {
            $found = $this->txt($selector.'._domainkey.'.$domain, fn ($v) => str_contains($v, 'p='));
            if ($found !== null) {
                [$dkim, $dkimSelector] = [$found, $selector];
                break;
            }
        }
        $expected = $direct ? $account?->dkimRecord() : null; // @phpstan-ignore nullsafe.neverNull
        $dkimOk = $dkim !== null && ($expected === null || str_replace(' ', '', $dkim) === str_replace(' ', '', $expected));
        $checks[] = [
            'record' => 'DKIM', 'status' => $dkimOk ? 'ok' : ($direct ? 'error' : 'warning'),
            'found' => $dkim ? $dkimSelector.'._domainkey: '.Str::limit($dkim, 80) : null,
            'suggestion' => $expected && ! $dkimOk ? ($account->dkim_selector ?? '')."._domainkey.{$domain}. TXT \"{$expected}\"" : null,
            'detail' => $direct
                ? (self::canSignDkim() ? __('system.dns.dkim_direct') : __('system.dns.dkim_unsupported'))
                : ($dkim ? '' : __('system.dns.dkim_relay')),
        ];

        // DMARC
        $dmarc = $this->txt('_dmarc.'.$domain, fn ($v) => str_starts_with(strtolower($v), 'v=dmarc1'));
        $policy = $dmarc && preg_match('/\bp=(\w+)/i', $dmarc, $m) ? strtolower($m[1]) : null;
        $suggested = "_dmarc.{$domain}. TXT \"v=DMARC1; p=reject; adkim=s; aspf=s; rua=mailto:dmarc@{$domain}\"";
        $checks[] = [
            'record' => 'DMARC', 'status' => $policy === 'reject' ? 'ok' : ($dmarc ? 'warning' : 'error'),
            'found' => $dmarc, 'suggestion' => $policy === 'reject' ? null : $suggested,
            'detail' => $dmarc ? ($policy === 'reject' ? '' : __('system.dns.dmarc_not_reject', ['policy' => $policy ?? '–'])) : __('system.dns.dmarc_missing'),
        ];

        return ['domain' => $domain, 'account' => $account->name ?? __('system.dns.server_settings'), 'direct' => $direct, 'checks' => $checks];
    }

    public static function canSignDkim(): bool
    {
        return extension_loaded('openssl') && class_exists(DkimSigner::class);
    }

    /** First TXT record of a name matching the filter. */
    private function txt(string $name, callable $filter): ?string
    {
        $records = $this->resolver !== null ? ($this->resolver)($name, DNS_TXT) : (@dns_get_record($name, DNS_TXT) ?: []);
        foreach ($records as $record) {
            $value = (string) ($record['txt'] ?? implode('', (array) ($record['entries'] ?? [])));
            if ($filter($value)) {
                return $value;
            }
        }

        return null;
    }
}
