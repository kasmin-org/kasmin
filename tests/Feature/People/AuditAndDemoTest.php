<?php

namespace Tests\Feature\People;

use App\Models\AuditEvent;
use App\Models\Household;
use App\Models\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditAndDemoTest extends TestCase
{
    use RefreshDatabase;

    public function test_changes_are_audited_with_old_and_new_values(): void
    {
        $person = Person::factory()->create(['first_name' => 'Alex']);
        $person->update(['first_name' => 'Sam']);

        $event = AuditEvent::query()->where('action', 'Person.updated')->firstOrFail();
        $this->assertEquals(['old' => 'Alex', 'new' => 'Sam'], $event->changes['first_name'] ?? null);
    }

    public function test_audit_log_is_append_only(): void
    {
        Person::factory()->create();
        $event = AuditEvent::query()->firstOrFail();

        $this->expectException(LogicException::class);
        $event->update(['action' => 'tampered']);
    }

    public function test_demo_data_is_generated_in_both_languages(): void
    {
        $this->artisan('kasmin:demo', ['--locale' => 'de', '--families' => 3, '--staff' => 2])->assertSuccessful(); // @phpstan-ignore method.nonObject
        $this->artisan('kasmin:demo', ['--locale' => 'en', '--families' => 2, '--staff' => 1, '--seed' => 7])->assertSuccessful(); // @phpstan-ignore method.nonObject

        $this->assertSame(5, Household::query()->count());
        $this->assertTrue(Household::query()->where('name', 'like', 'Familie %')->exists());
        $this->assertTrue(Household::query()->where('name', 'like', 'Family %')->exists());
        $this->assertGreaterThan(0, Person::query()->whereHas('guardians')->count());
    }
}
