<?php

namespace App\Http\Middleware;

use App\Support\Api\ApiContext;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * X07: a write request with the same "Idempotency-Key" is carried out once; a repetition gets the first answer.
 */
class Idempotency
{
    public const HOURS = 24;

    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) $request->header('Idempotency-Key');
        $token = ApiContext::token();
        if ($key === '' || $token === null || $request->isMethodSafe()) {
            return $next($request);
        }
        if (strlen($key) > 200) {
            return response()->json(['message' => 'The Idempotency-Key is too long.'], 422);
        }
        $hash = hash('sha256', $request->method().'|'.$request->getPathInfo().'|'.$request->getContent());
        $where = ['api_token_id' => $token->id, 'key_hash' => hash('sha256', $key)];
        DB::table('api_idempotency_keys')->where($where)->where('created_at', '<', now()->subHours(self::HOURS))->delete();
        try {
            $id = DB::table('api_idempotency_keys')->insertGetId($where + ['request_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
        } catch (UniqueConstraintViolationException) {
            $row = DB::table('api_idempotency_keys')->where($where)->first();

            return $this->repeat($row !== null ? (array) $row : null, $hash);
        }
        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            DB::table('api_idempotency_keys')->where('id', $id)->delete(); // may be tried again

            throw $e;
        }
        if ($response->getStatusCode() >= 500 || $response->getStatusCode() === 429) {
            DB::table('api_idempotency_keys')->where('id', $id)->delete();
        } else {
            DB::table('api_idempotency_keys')->where('id', $id)->update(['status' => $response->getStatusCode(), 'body' => Crypt::encryptString((string) $response->getContent()), 'updated_at' => now()]);
        }

        return $response;
    }

    /** @param  array<string, mixed>|null  $row */
    private function repeat(?array $row, string $hash): Response
    {
        if ($row === null || $row['status'] === null) {
            return response()->json(['message' => 'The first request with this Idempotency-Key is still running.'], 409);
        }
        if (! hash_equals((string) $row['request_hash'], $hash)) {
            return response()->json(['message' => 'This Idempotency-Key was used for another request.'], 422);
        }

        return response(Crypt::decryptString((string) $row['body']), (int) $row['status'], ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']);
    }

    public static function prune(): int
    {
        return DB::table('api_idempotency_keys')->where('created_at', '<', now()->subHours(self::HOURS))->delete();
    }
}
