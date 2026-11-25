<?php

namespace Tests\Feature\System;

use App\Models\ContactPoint;
use App\Models\Household;
use App\Models\MailTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class HookTest extends TestCase
{
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->token = str_repeat('a1B2', 10);
        Schema::dropAllTables();
    }

    protected function tearDown(): void
    {
        Schema::dropAllTables();
        RefreshDatabaseState::$migrated = false;
        parent::tearDown();
    }

    public function test_hooks_are_disabled_without_or_with_a_wrong_token(): void
    {
        config(['kasmin.hook_token' => null]);
        $this->postJson('/system/hooks/upgrade')->assertNotFound();
        config(['kasmin.hook_token' => $this->token]);
        $this->postJson('/system/hooks/upgrade', [], ['Authorization' => 'Bearer wrong'])->assertNotFound();
        config(['kasmin.hook_token' => 'short']);
        $this->postJson('/system/hooks/upgrade', [], ['Authorization' => 'Bearer short'])->assertNotFound();
    }

    public function test_demo_hook_installs_demo_data_once_and_only_in_demo_environments(): void
    {
        config(['kasmin.hook_token' => $this->token, 'kasmin.demo.locale' => 'de', 'kasmin.demo.admin_email' => 'admin@demo.example.org', 'kasmin.demo.email_domain' => 'demo.example.org']);
        $headers = ['Authorization' => 'Bearer '.$this->token];

        $this->postJson('/system/hooks/demo', [], $headers)->assertNotFound(); // testing environment ≠ demo

        $this->app['env'] = 'demo';
        $this->postJson('/system/hooks/demo', [], $headers)->assertOk()->assertJson(['installed' => true]);
        $this->assertTrue(User::query()->where('email', 'admin@demo.example.org')->exists());
        $this->assertTrue(Household::query()->where('name', 'like', 'Familie %')->exists());
        $this->assertSame(0, ContactPoint::query()->where('type', 'email')->where('value', 'not like', '%demo.example.org')->count());

        $this->postJson('/system/hooks/demo', [], $headers)->assertOk()->assertJson(['installed' => false]);
        $admin = User::query()->where('email', 'admin@demo.example.org')->value('id');
        User::query()->whereKey($admin)->update(['name' => 'Changed']);
        $this->postJson('/system/hooks/demo?reset=1', [], $headers)->assertOk()->assertJson(['installed' => true]);
        $this->assertSame('Demo Admin', User::query()->where('email', 'admin@demo.example.org')->value('name'));
        $this->assertGreaterThan(10, MailTemplate::query()->count());
        $this->postJson('/system/hooks/upgrade', [], $headers)->assertOk()->assertJson(['ok' => true]);
        $this->getJson('/system/cron?token='.$this->token)->assertOk()->assertJson(['ok' => true]);
    }
}
