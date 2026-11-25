<?php

namespace App\Support\Audit;

use App\Support\Settings;
use Illuminate\Support\Facades\DB;

/**
 * Data protection for the audit log itself (AU03, AU05): after some days the IP address and the browser are
 * shortened, after some years entries are deleted. The log stays append-only for the application; only this
 * daily job touches old entries, directly in the table.
 */
class AuditRetention
{
    /** @return array{shortened: int, deleted: int} */
    public function run(): array
    {
        $shortened = 0;
        $days = (int) Settings::get('audit_ip_days');
        if ($days > 0) {
            $rows = DB::table('audit_events')->where('occurred_at', '<', now()->subDays($days))
                ->where(fn ($q) => $q->where(fn ($ip) => $ip->whereNotNull('ip')->where('ip', 'not like', '%.0')->where('ip', 'not like', '%::'))
                    ->orWhere('user_agent', 'like', '%/%'))
                ->orderBy('id')->limit(5000)->get(['id', 'ip', 'user_agent']);
            foreach ($rows as $row) {
                DB::table('audit_events')->where('id', $row->id)->update(['ip' => self::shortIp($row->ip), 'user_agent' => self::shortAgent($row->user_agent)]);
                $shortened++;
            }
        }
        $years = (int) Settings::get('retention_audit_years');
        $deleted = $years > 0 ? DB::table('audit_events')->where('occurred_at', '<', now()->subYears($years))->delete() : 0;

        return ['shortened' => $shortened, 'deleted' => $deleted];
    }

    /** IPv4 without the last block, IPv6 without everything after the first three blocks. */
    public static function shortIp(?string $ip): ?string
    {
        if ($ip === null || $ip === '') {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return preg_replace('/\.\d+$/', '.0', $ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = (string) inet_pton($ip);

            return implode(':', array_map(fn (string $block) => ltrim(bin2hex($block), '0') ?: '0', str_split(substr($packed, 0, 6), 2))).'::';
        }

        return null;
    }

    /** Only the kind of browser and system, e.g. "Firefox, Windows". */
    public static function shortAgent(?string $agent): ?string
    {
        if ($agent === null || $agent === '' || ! str_contains($agent, '/')) {
            return $agent;
        }
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Other',
        };
        $system = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'other system',
        };

        return $browser.', '.$system;
    }
}
