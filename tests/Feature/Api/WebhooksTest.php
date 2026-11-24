<?php

namespace Tests\Feature\Api;

use App\Models\Event;
use App\Models\Person;
use App\Models\Webhook;
use App\Models\WebhookDelivery;
use App\Support\Events\Registrations;
use App\Support\Install\Defaults;
use App\Support\Webhooks\Webhooks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhooksTest extends TestCase
{
    use RefreshDatabase;

    public function test_events_are_signed_delivered_and_retried(): void
    {
        app(Defaults::class)->install('en');
        $hook = Webhook::query()->create(['name' => 'crm', 'url' => 'https://hooks.example.org/kasmin', 'events' => ['participation.created'], 'secret' => 'top-secret', 'active' => true]);
        Webhook::query()->create(['name' => 'off', 'url' => 'https://other.example.org', 'events' => ['*'], 'secret' => 'x', 'active' => false]);
        $event = Event::query()->create(['title' => 'Camp', 'starts_at' => now()->addMonth(), 'ends_at' => now()->addMonth()->addWeek(), 'status' => 'open']);
        $event->roles()->create(['role_key' => 'child', 'capacity' => 5]);
        app(Registrations::class)->register($event, Person::factory()->create(['birth_date' => now()->subYears(10)]), 'child');

        $this->assertSame(1, WebhookDelivery::query()->count(), 'only the subscribed, active webhook');
        Http::fake(['hooks.example.org/*' => Http::sequence()->push('down', 503)->push('ok', 200)]);

        $this->assertSame(0, app(Webhooks::class)->deliver());
        $delivery = WebhookDelivery::query()->firstOrFail();
        $this->assertSame(['pending', 1, 503], [$delivery->status, $delivery->attempts, $delivery->response_code]);
        $this->travel(2)->minutes();
        $this->assertSame(1, app(Webhooks::class)->deliver());
        $this->assertSame('sent', $delivery->refresh()->status);

        Http::assertSent(function (Request $request) use ($hook) {
            $signature = 'sha256='.Webhooks::signature($hook->secret, $request->header('X-Kasmin-Timestamp')[0], $request->body());

            return $request->header('X-Kasmin-Signature')[0] === $signature && $request->header('X-Kasmin-Event')[0] === 'participation.created'
                && str_contains($request->body(), '"role":"child"');
        });
    }

    public function test_http_urls_are_refused_and_give_up_after_retries(): void
    {
        Webhook::query()->create(['name' => 'plain', 'url' => 'http://insecure.example.org', 'events' => ['person.created'], 'secret' => 's', 'active' => true]);
        Person::factory()->create();
        Http::fake();
        for ($i = 0; $i < 7; $i++) {
            app(Webhooks::class)->deliver();
            $this->travel(13)->hours();
        }
        $delivery = WebhookDelivery::query()->firstOrFail();
        $this->assertSame('failed', $delivery->status);
        $this->assertStringContainsString('https', (string) $delivery->error);
        Http::assertNothingSent();
    }
}
