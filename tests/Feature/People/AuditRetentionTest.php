<?php

namespace Tests\Feature\People;

use App\Models\AuditEvent;
use App\Support\Audit\AuditRetention;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The audit log keeps IP addresses and browsers only for a short time and ends after the retention period (AU03, AU05).
 */
class AuditRetentionTest extends TestCase
{
    use RefreshDatabase;

    private function entry(string $when, ?string $ip, ?string $agent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:140.0) Gecko/20100101 Firefox/140.0'): AuditEvent
    {
        return AuditEvent::query()->create(['action' => 'test', 'actor_type' => 'system', 'occurred_at' => $when, 'ip' => $ip, 'user_agent' => $agent]);
    }

    public function test_old_entries_lose_the_exact_address_and_browser(): void
    {
        $old = $this->entry(now()->subDays(31)->toDateTimeString(), '203.0.113.77');
        $six = $this->entry(now()->subDays(40)->toDateTimeString(), '2001:db8:12:3456:aaaa:bbbb:cccc:dddd', 'Mozilla/5.0 (iPhone; CPU iPhone OS 19_0 like Mac OS X) AppleWebKit/605.1.15 Version/19.0 Mobile/15E148 Safari/604.1');
        $new = $this->entry(now()->subDays(29)->toDateTimeString(), '203.0.113.78');
        $system = $this->entry(now()->subDays(90)->toDateTimeString(), null, null);

        $this->assertSame(['shortened' => 2, 'deleted' => 0], app(AuditRetention::class)->run());
        $this->assertSame(['203.0.113.0', 'Firefox, Windows'], [$old->refresh()->ip, $old->user_agent]);
        $this->assertSame(['2001:db8:12::', 'Safari, iOS'], [$six->refresh()->ip, $six->user_agent]);
        $this->assertSame('203.0.113.78', $new->refresh()->ip);
        $this->assertNull($system->refresh()->ip);
        $this->assertSame(['shortened' => 0, 'deleted' => 0], app(AuditRetention::class)->run(), 'nothing is done twice');
    }

    public function test_entries_end_after_the_retention_period_and_both_can_be_switched_off(): void
    {
        $this->entry(now()->subYears(11)->toDateTimeString(), '203.0.113.77');
        $this->entry(now()->subYears(9)->toDateTimeString(), '203.0.113.77');

        Settings::set('audit_ip_days', '0');
        Settings::set('retention_audit_years', '0');
        $this->assertSame(['shortened' => 0, 'deleted' => 0], app(AuditRetention::class)->run());

        Settings::set('retention_audit_years', '10');
        $this->assertSame(['shortened' => 0, 'deleted' => 1], app(AuditRetention::class)->run());
        $this->assertSame(1, AuditEvent::query()->where('action', 'test')->count());
    }
}
