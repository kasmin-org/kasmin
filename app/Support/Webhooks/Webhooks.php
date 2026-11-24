<?php

namespace App\Support\Webhooks;

use App\Models\Webhook;
use App\Models\WebhookDelivery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Outgoing webhooks (AD81, I04): events are queued per subscribed webhook and delivered by the scheduler as HTTPS
 * POST with an HMAC-SHA256 signature over "<timestamp>.<body>" (header X-Kasmin-Signature: sha256=…). Failed
 * deliveries are retried with growing pauses (1 min … 12 h, 6 attempts). Certificates are always checked.
 */
class Webhooks
{
    /** Pauses in minutes before the 2nd, 3rd … attempt. */
    public const BACKOFF = [1, 5, 30, 120, 720];

    /** @var Collection<int, Webhook>|null active webhooks, cached per process */
    private static ?Collection $active = null;

    public static function flush(): void
    {
        self::$active = null;
    }

    /** @param  array<string, mixed>  $data */
    public function emit(string $event, array $data): int
    {
        $count = 0;
        try {
            self::$active ??= Webhook::query()->where('active', true)->get();
        } catch (\Throwable) {
            return 0; // before installation
        }
        foreach (self::$active as $hook) {
            if (! $hook->wants($event)) {
                continue;
            }
            $hook->deliveries()->create([
                'uuid' => (string) Str::uuid(), 'event' => $event, 'status' => 'pending', 'next_attempt_at' => now(),
                'payload' => ['event' => $event, 'occurred_at' => now()->toIso8601String(), 'data' => $data],
            ]);
            $count++;
        }

        return $count;
    }

    /** Sends due deliveries; returns the number of successful ones. */
    public function deliver(int $limit = 50): int
    {
        $sent = 0;
        $due = WebhookDelivery::query()->where('status', 'pending')->where('next_attempt_at', '<=', now())->with('webhook')->orderBy('id')->limit($limit)->get();
        foreach ($due as $delivery) {
            $hook = $delivery->webhook;
            if ($hook === null || ! $hook->active) {
                $delivery->forceFill(['status' => 'failed', 'error' => 'webhook inactive'])->save();

                continue;
            }
            $body = (string) json_encode($delivery->payload + ['delivery' => $delivery->uuid], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $timestamp = (string) now()->timestamp;
            $attempt = $delivery->attempts + 1;
            try {
                if (! str_starts_with($hook->url, 'https://')) {
                    throw new \RuntimeException('Only https URLs are allowed.');
                }
                $response = Http::timeout(10)->withoutRedirecting()->withHeaders([
                    'Content-Type' => 'application/json', 'User-Agent' => 'Kasmin-Webhooks/'.config('kasmin.version'),
                    'X-Kasmin-Event' => $delivery->event, 'X-Kasmin-Delivery' => $delivery->uuid, 'X-Kasmin-Timestamp' => $timestamp,
                    'X-Kasmin-Signature' => 'sha256='.self::signature($hook->secret, $timestamp, $body),
                ])->withBody($body, 'application/json')->post($hook->url);
                $ok = $response->successful();
                $delivery->forceFill(['attempts' => $attempt, 'response_code' => $response->status(), 'error' => $ok ? null : Str::limit($response->body(), 480)])->save();
            } catch (\Throwable $e) {
                $ok = false;
                $delivery->forceFill(['attempts' => $attempt, 'response_code' => null, 'error' => Str::limit($e->getMessage(), 480)])->save();
            }
            if ($ok) {
                $delivery->forceFill(['status' => 'sent', 'sent_at' => now(), 'next_attempt_at' => null])->save();
                $sent++;
            } elseif ($attempt > count(self::BACKOFF)) {
                $delivery->forceFill(['status' => 'failed', 'next_attempt_at' => null])->save();
            } else {
                $delivery->forceFill(['next_attempt_at' => now()->addMinutes(self::BACKOFF[$attempt - 1])])->save();
            }
        }

        return $sent;
    }

    public static function signature(string $secret, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, $secret);
    }
}
